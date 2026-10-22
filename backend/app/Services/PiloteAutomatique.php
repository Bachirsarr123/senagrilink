<?php

namespace App\Services;

use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\LivraisonController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Requests\AssignerTransporteurReservationRequest;
use App\Http\Requests\StoreLivraisonRequest;
use App\Models\Commande;
use App\Models\Entrepot;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Utilisateur;
use App\Notifications\OperationModifieeNotification;
use App\Support\CircuitDepot;
use App\Support\PropositionTransporteur;
use App\Support\SuiviGps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pilote automatique de l'entrepôt (lot 11). Il fait, au nom du gestionnaire,
 * ce que le système sait décider seul : accepter un dépôt quand la place le
 * permet, confirmer une commande (stock déjà réservé), confier chaque mission
 * au transporteur libre le plus proche, puis au suivant après un refus ou sans
 * réponse dans le délai. Il passe par les mêmes règles que le gestionnaire
 * (mêmes contrôles, mêmes notifications, même historique, marqué
 * « automatique »). Le gestionnaire garde la réception, la préparation, les
 * incidents et les refus ; chaque règle se coupe dans ses réglages.
 */
class PiloteAutomatique
{
    /** Vrai pendant une action du pilote : l'historique la marque « automatique ». */
    public static bool $enCours = false;

    public const MOTIF = 'Pilote automatique';

    // ── Points d'entrée ──────────────────────────────────────────────────────

    /** Nouveau dépôt demandé : acceptation, puis transporteur. */
    public function depotDemande(Reservation $r): void
    {
        $e = Entrepot::find($r->entrepot_id);
        if (self::$enCours || ! $e?->auto_accepter_depots || ! $g = $this->gestionnaire($e)) {
            return;
        }
        $reponse = $this->appeler($g, fn (Request $q) => app(ReservationController::class)->confirmer($q, $r->id));
        if ($reponse?->isSuccessful() && $r->mode_transport !== CircuitDepot::MODE_PRODUCTEUR) {
            $this->confierDepot($r->fresh());
        }
    }

    /** Nouvelles commandes : confirmées d'office (le stock est déjà réservé). */
    public function commandesPassees(iterable $commandes): void
    {
        foreach ($commandes as $c) {
            $e = $c->stock?->entrepot ?? Entrepot::find($c->stock?->entrepot_id);
            if (self::$enCours || ! $e?->auto_confirmer_commandes || ! $g = $this->gestionnaire($e)) {
                continue;
            }
            $this->appeler($g, fn (Request $q) => app(CommandeController::class)->confirmer($q, $c->id));
        }
    }

    /** Commande prête : la livraison est confiée au transporteur libre le plus proche de l'entrepôt. */
    public function commandePrete(Commande $c): void
    {
        $e = $c->stock?->entrepot ?? Entrepot::find($c->stock?->entrepot_id);
        if (self::$enCours || ! $e?->auto_affecter_transporteurs || ! $g = $this->gestionnaire($e)) {
            return;
        }
        $t = $this->choisir($e->latitude, $e->longitude, []);
        if (! $t) {
            $this->prevenirSansTransporteur($g, 'commande', $c->id, $c->numero_commande);

            return;
        }
        $this->appeler($g, fn (Request $q) => app(LivraisonController::class)->store(
            $this->formulaire(StoreLivraisonRequest::class, $q, ['commande_id' => $c->id, 'transporteur_id' => $t]),
        ));
    }

    /** Mission refusée par un transporteur : proposée au suivant. */
    public function missionRefusee(string $type, int $id): void
    {
        if (self::$enCours) {
            return;
        }
        $type === 'reservation'
            ? (($r = Reservation::find($id)) && $this->confierDepot($r))
            : (($l = Livraison::with('commande.stock.entrepot')->find($id)) && $this->confierLivraison($l));
    }

    /**
     * Missions restées sans réponse au-delà du délai de l'entrepôt : retirées au
     * transporteur silencieux et proposées au suivant. Appelé chaque minute par
     * le planificateur, et au fil des requêtes (Render gratuit n'a pas de tâche planifiée).
     */
    public function relancerSansReponse(): int
    {
        $n = 0;
        foreach (Reservation::with('entrepot')->where('statut', 'confirmee')->whereNotNull('transporteur_id')->whereNull('mission_acceptee_le')->get() as $r) {
            if ($r->entrepot?->auto_affecter_transporteurs && $this->expiree('reservation', $r->id, $r->entrepot)) {
                $n += (int) $this->confierDepot($r);
            }
        }
        foreach (Livraison::with('commande.stock.entrepot')->where('statut', 'en_attente')->whereNotNull('transporteur_id')->whereNull('mission_acceptee_le')->get() as $l) {
            $e = $l->commande?->stock?->entrepot;
            if ($e?->auto_affecter_transporteurs && $this->expiree('livraison', $l->id, $e)) {
                $n += (int) $this->confierLivraison($l);
            }
        }

        return $n;
    }

    /** Au plus une relance par minute, quel que soit le nombre de requêtes. */
    public function relancerSiNecessaire(): void
    {
        if (Cache::add('pilote:relance', true, 60)) {
            try {
                $this->relancerSansReponse();
                app(Paiements::class)->expirer(); // lot 13 : commandes non payées à temps
            } catch (Throwable $e) {
                report($e); // jamais d'erreur pour la requête en cours
            }
        }
    }

    // ── Affectations ─────────────────────────────────────────────────────────

    private function confierDepot(Reservation $r): bool
    {
        $e = Entrepot::find($r->entrepot_id);
        if (! $e?->auto_affecter_transporteurs || ! $g = $this->gestionnaire($e)) {
            return false;
        }
        $depart = SuiviGps::extremites($r->loadMissing('producteur.utilisateur', 'entrepot'))['origine'];
        $t = $this->choisir($depart['latitude'], $depart['longitude'], $this->ecartes('reservation', $r->id, $r->transporteur_id));
        if (! $t) {
            $this->prevenirSansTransporteur($g, 'reservation', $r->id, $r->numero_reservation);

            return false;
        }
        $q = $this->requete($g, ['transporteur_id' => $t, 'motif' => self::MOTIF]);

        return (bool) $this->appeler($g, fn () => app(ReservationController::class)->assignerTransporteur(
            $this->formulaire(AssignerTransporteurReservationRequest::class, $q, ['transporteur_id' => $t, 'motif' => self::MOTIF]), $r->id,
        ))?->isSuccessful();
    }

    private function confierLivraison(Livraison $l): bool
    {
        $e = $l->commande?->stock?->entrepot;
        if (! $e?->auto_affecter_transporteurs || ! $g = $this->gestionnaire($e)) {
            return false;
        }
        $t = $this->choisir($e->latitude, $e->longitude, $this->ecartes('livraison', $l->id, $l->transporteur_id));
        if (! $t) {
            $this->prevenirSansTransporteur($g, 'livraison', $l->id, $l->numero_livraison);

            return false;
        }

        return (bool) $this->appeler($g, fn (Request $q) => app(LivraisonController::class)->changerTransporteur($q, $l->id),
            ['transporteur_id' => $t, 'motif' => self::MOTIF])?->isSuccessful();
    }

    /** Le meilleur transporteur qui n'a pas déjà refusé ou laissé passer la mission. */
    private function choisir(?float $lat, ?float $lng, array $ecartes): ?int
    {
        return PropositionTransporteur::classer($lat, $lng)->first(fn ($t) => ! in_array($t->id, $ecartes, true))?->id;
    }

    /** Transporteurs déjà sollicités pour cette mission (refus, sans réponse) et l'actuel. */
    private function ecartes(string $type, int $id, ?int $actuel): array
    {
        $anciens = HistoriqueOperation::where('sujet_type', $type)->where('sujet_id', $id)
            ->whereIn('action', ['refus_mission', 'reassignation_transporteur', 'changement_transporteur'])
            ->get()->map(fn ($h) => (int) ($h->details['ancien_transporteur_id'] ?? 0))->filter()->all();

        return array_values(array_unique([...$anciens, ...($actuel ? [(int) $actuel] : [])]));
    }

    /** Proposée depuis plus longtemps que le délai de réponse de l'entrepôt ? */
    private function expiree(string $type, int $id, Entrepot $e): bool
    {
        $proposee = HistoriqueOperation::where('sujet_type', $type)->where('sujet_id', $id)
            ->whereIn('action', ['assignation_transporteur', 'reassignation_transporteur', 'creation', 'changement_transporteur'])
            ->max('created_at');

        return $proposee !== null && now()->diffInMinutes($proposee, true) >= max(1, (int) $e->delai_reponse_min);
    }

    private function prevenirSansTransporteur(Utilisateur $g, string $type, int $id, ?string $numero): void
    {
        // Une seule alerte par mission et par heure
        if (Cache::add("pilote:sans-transporteur:{$type}:{$id}", true, 3600)) {
            $g->notify(new OperationModifieeNotification('sans_transporteur', sprintf(
                'Aucun transporteur libre pour %s : choisissez-en un vous-même.', $numero ?? "la mission {$id}",
            ), [$type === 'commande' ? 'commande_id' : "{$type}_id" => $id]));
        }
    }

    // ── Exécution au nom du gestionnaire ─────────────────────────────────────

    private function gestionnaire(Entrepot $e): ?Utilisateur
    {
        $g = Utilisateur::find($e->utilisateur_id);

        return $g && $g->statut === Utilisateur::ACTIF ? $g : null;
    }

    private function requete(Utilisateur $g, array $donnees = []): Request
    {
        $q = Request::create('/api/pilote', 'POST', $donnees);
        $q->headers->set('Accept', 'application/json');
        $q->setUserResolver(fn () => $g);

        return $q;
    }

    /** @template T of FormRequest @param class-string<T> $classe @return T */
    private function formulaire(string $classe, Request $q, array $donnees): FormRequest
    {
        $f = $classe::createFrom($q);
        $f->replace($donnees);
        $f->setContainer(app())->setRedirector(app('redirect'));
        $f->setUserResolver($q->getUserResolver());
        $f->validateResolved();

        return $f;
    }

    /** Appel de l'action du gestionnaire ; une action refusée (règle métier) n'est jamais une erreur. */
    private function appeler(Utilisateur $g, callable $action, array $donnees = []): ?JsonResponse
    {
        $avant = self::$enCours;
        self::$enCours = true;
        try {
            return $action($this->requete($g, $donnees));
        } catch (ValidationException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            self::$enCours = $avant;
        }
    }
}
