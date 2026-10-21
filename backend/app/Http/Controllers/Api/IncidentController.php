<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Transporteur;
use App\Models\Utilisateur;
use App\Notifications\OperationModifieeNotification;
use App\Services\AnnulationCommande;
use App\Services\GestionStock;
use App\Support\CircuitDepot;
use App\Support\CircuitLivraison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Incidents (cahier des charges v2, §5.2) : le transporteur (ou le producteur
 * qui livre lui-même) déclare un incident de transport, l'acheteur une demande
 * d'annulation ou une anomalie à la réception ; le gestionnaire de l'entrepôt
 * concerné décide. Chaque déclaration et chaque décision est historisée et notifiée.
 */
class IncidentController extends Controller
{
    public function __construct(
        private readonly AnnulationCommande $annulation,
        private readonly GestionStock $gestionStock,
    ) {
    }

    /** Incidents visibles : ceux qu'on a déclarés, et ceux de son entrepôt (tous pour l'administrateur). */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = Incident::with('declarant:id,nom,prenom,role', 'resolveur:id,nom,prenom')
            ->when($request->statut, fn ($q, $s) => $q->where('statut', $s))
            ->orderByDesc('id');

        if ($u->role !== 'administrateur') {
            $entrepotId = $u->entrepot?->id;
            $q->where(function ($q) use ($u, $entrepotId) {
                $q->where('declare_par', $u->id);

                if ($entrepotId) {
                    $commandes = Commande::whereHas('stock', fn ($s) => $s->where('entrepot_id', $entrepotId))->select('id');
                    $q->orWhere(fn ($q) => $q->where('sujet_type', 'commande')->whereIn('sujet_id', $commandes))
                      ->orWhere(fn ($q) => $q->where('sujet_type', 'livraison')->whereIn('sujet_id', Livraison::whereIn('commande_id', $commandes)->select('id')))
                      ->orWhere(fn ($q) => $q->where('sujet_type', 'reservation')->whereIn('sujet_id', Reservation::where('entrepot_id', $entrepotId)->select('id')));
                }
            });
        }

        $incidents = $q->get()->map(fn (Incident $i) => array_merge($i->toArray(), [
            'sujet'     => $this->resumeSujet($i),
            'decisions' => $this->peutDecider($i, $u) && $i->statut === 'ouvert' ? $this->decisionsPossibles($i) : [],
        ]));

        return response()->json(['incidents' => $incidents]);
    }

    /** Déclarer un incident. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'sujet_type'  => ['required', Rule::in(array_keys(Incident::TYPES))],
            'sujet_id'    => ['required', 'integer'],
            'type'        => ['required', 'string'],
            'description' => ['required', 'string', 'max:1000'],
        ], ['description.required' => 'Décrivez ce qui se passe.']);

        if (! in_array($donnees['type'], Incident::TYPES[$donnees['sujet_type']], true)) {
            return response()->json(['message' => 'Type d\'incident inconnu pour cette opération.'], 422);
        }

        $u       = $request->user();
        $incident = DB::transaction(function () use ($donnees, $u) {
            $refus = match ($donnees['sujet_type']) {
                'livraison'   => $this->controlerLivraison($donnees['sujet_id'], $u),
                'reservation' => $this->controlerReservation($donnees['sujet_id'], $u),
                default       => $this->controlerCommande($donnees['sujet_id'], $donnees['type'], $u),
            };

            if ($refus) {
                return $refus;
            }

            $ouvert = Incident::ouverts()->where('sujet_type', $donnees['sujet_type'])->where('sujet_id', $donnees['sujet_id'])
                ->where('type', $donnees['type'])->exists();

            if ($ouvert) {
                return response()->json(['message' => 'Un incident de ce type est déjà ouvert : l\'entrepôt va décider.'], 422);
            }

            $incident = Incident::create([...$donnees, 'statut' => 'ouvert', 'declare_par' => $u->id]);
            HistoriqueOperation::consigner($donnees['sujet_type'], $donnees['sujet_id'], 'incident_'.$donnees['type'], $u, null, null, $donnees['description'],
                ['incident_id' => $incident->id]);

            if ($donnees['sujet_type'] === 'commande' && in_array($donnees['type'], Incident::ANOMALIES, true)) {
                $this->terminerAvecLitige(Commande::whereKey($donnees['sujet_id'])->lockForUpdate()->first(), $u);
            }

            return $incident;
        });

        if ($incident instanceof JsonResponse) {
            return $incident;
        }

        $this->notifier($this->gestionnaire($incident), $incident, sprintf(
            'Incident « %s » déclaré sur %s : %s. Une décision est attendue.',
            $this->libelle($incident->type), $this->resumeSujet($incident)['numero'] ?? $incident->sujet_type, $incident->description,
        ));

        if ($incident->sujet_type === 'livraison') {
            $this->notifier(Livraison::with('commande.acheteur.utilisateur')->find($incident->sujet_id)?->commande?->acheteur?->utilisateur,
                $incident, 'Un incident retarde votre livraison : '.$this->libelle($incident->type).'. L\'entrepôt vous tiendra informé.');
        }

        return response()->json([
            'message'  => $incident->type === 'demande_annulation'
                ? 'Demande d\'annulation envoyée à l\'entrepôt.'
                : 'Incident déclaré. L\'entrepôt a été prévenu.',
            'incident' => $incident,
        ], 201);
    }

    /** Décision du gestionnaire sur un incident ouvert. */
    public function resoudre(Request $request, int $id): JsonResponse
    {
        $incident = Incident::find($id);

        if (! $incident) {
            return response()->json(['message' => 'Incident introuvable.'], 404);
        }

        if (! $this->peutDecider($incident, $request->user())) {
            return response()->json(['message' => 'Seul l\'entrepôt concerné décide de la suite.'], 403);
        }

        $donnees = $request->validate([
            'decision'        => ['required', Rule::in(Incident::DECISIONS[$incident->sujet_type])],
            'motif'           => ['nullable', 'string', 'max:500'],
            'transporteur_id' => ['required_if:decision,changer_transporteur', 'nullable', 'integer', 'exists:transporteurs,id'],
        ], [
            'transporteur_id.required_if' => 'Choisissez le nouveau transporteur.',
        ]);

        if (in_array($donnees['decision'], ['refuser_annulation', 'cloturer_litige', 'annuler_commande', 'annuler_depot', 'reprendre_marchandise'], true)
            && empty($donnees['motif'])) {
            return response()->json(['message' => 'Indiquez le motif de la décision.', 'errors' => ['motif' => ['Indiquez le motif de la décision.']]], 422);
        }

        if (! in_array($donnees['decision'], $this->decisionsPossibles($incident), true)) {
            return response()->json(['message' => 'Cette décision n\'est pas possible pour cet incident.'], 422);
        }

        $aPrevenir = [];
        $resultat  = DB::transaction(function () use ($incident, $donnees, $request, &$aPrevenir) {
            $i = Incident::whereKey($incident->id)->lockForUpdate()->first();

            if ($i->statut !== 'ouvert') {
                return response()->json(['message' => 'Cet incident est déjà traité.'], 422);
            }

            $refus = $this->appliquer($i, $donnees, $request->user(), $aPrevenir);

            if ($refus) {
                return $refus;
            }

            $i->fill([
                'statut'         => 'resolu',
                'decision'       => $donnees['decision'],
                'motif_decision' => $donnees['motif'] ?? null,
                'resolu_par'     => $request->user()->id,
                'resolu_le'      => now(),
            ])->save();
            HistoriqueOperation::consigner($i->sujet_type, $i->sujet_id, 'decision_'.$donnees['decision'], $request->user(), null, null,
                $donnees['motif'] ?? null, ['incident_id' => $i->id]);

            return $i;
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $message = sprintf('Décision de l\'entrepôt sur l\'incident « %s » : %s.%s', $this->libelle($resultat->type),
            $this->libelle($resultat->decision), $resultat->motif_decision ? ' Motif : '.$resultat->motif_decision : '');
        $this->notifier(Utilisateur::find($resultat->declare_par), $resultat, $message);

        foreach ($aPrevenir as [$utilisateur, $texte]) {
            $this->notifier($utilisateur, $resultat, $texte);
        }

        return response()->json(['message' => 'Décision enregistrée. Les personnes concernées ont été prévenues.', 'incident' => $resultat]);
    }

    // ── Déclaration : qui peut déclarer quoi ─────────────────────────────────

    private function controlerLivraison(int $id, Utilisateur $u): ?JsonResponse
    {
        $l = Livraison::whereKey($id)->lockForUpdate()->first();

        if (! $l || $u->transporteur === null || (int) $l->transporteur_id !== (int) $u->transporteur->id) {
            return response()->json(['message' => 'Seul le transporteur de la livraison peut y déclarer un incident.'], 403);
        }

        return in_array($l->statut, CircuitLivraison::INCIDENT_POSSIBLE, true) ? null
            : response()->json(['message' => 'Un incident se déclare pendant la livraison (mission acceptée, pas encore remise).', 'statut_actuel' => $l->statut], 422);
    }

    private function controlerReservation(int $id, Utilisateur $u): ?JsonResponse
    {
        $r = Reservation::whereKey($id)->lockForUpdate()->first();

        if (! $r || ! CircuitDepot::estConducteur($r, $u)) {
            return response()->json(['message' => 'Seule la personne qui transporte la marchandise peut déclarer un incident.'], 403);
        }

        return in_array($r->statut, CircuitDepot::INCIDENT_POSSIBLE, true) ? null
            : response()->json(['message' => 'Un incident se déclare pendant l\'acheminement.', 'statut_actuel' => $r->statut], 422);
    }

    private function controlerCommande(int $id, string $type, Utilisateur $u): ?JsonResponse
    {
        $c = Commande::with('livraison')->whereKey($id)->lockForUpdate()->first();

        if (! $c || $u->acheteurGros === null || (int) $c->acheteur_id !== (int) $u->acheteurGros->id) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $actions = CircuitLivraison::actionsCommande($c, $u);
        $attendue = $type === 'demande_annulation' ? 'demander_annulation' : 'declarer_anomalie';

        if (! in_array($attendue, $actions, true)) {
            return response()->json([
                'message'       => $type === 'demande_annulation'
                    ? 'La commande ne peut plus être annulée : elle est déjà chargée.'
                    : 'Une anomalie se déclare à l\'arrivée de la livraison.',
                'statut_actuel' => $c->statut,
            ], 422);
        }

        return null;
    }

    /** Anomalie à la réception : la livraison est remise, la commande passe en litige. */
    private function terminerAvecLitige(Commande $c, Utilisateur $u): void
    {
        $l = Livraison::where('commande_id', $c->id)->where('statut', 'arrivee')->lockForUpdate()->first();

        if ($l) {
            $l->statut         = 'livree';
            $l->date_livraison = now();
            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, 'livraison', $u, 'arrivee', 'livree', null, ['mode' => 'anomalie']);
        }

        $c->statut = 'litige';
        $c->save();
        HistoriqueOperation::consigner('commande', $c->id, 'litige', $u, 'en_livraison', 'litige');
    }

    // ── Décision ─────────────────────────────────────────────────────────────

    /** Décisions possibles selon le sujet et son état actuel. */
    private function decisionsPossibles(Incident $i): array
    {
        if ($i->sujet_type === 'commande') {
            return $i->type === 'demande_annulation'
                ? ['accepter_annulation', 'refuser_annulation']
                : ['cloturer_litige', 'reprendre_marchandise'];
        }

        if ($i->sujet_type === 'reservation') {
            $r = Reservation::find($i->sujet_id);
            $d = ['reprendre'];
            if ($r?->mode_transport === CircuitDepot::MODE_TRANSPORTEUR) {
                $d[] = 'changer_transporteur';
            }
            $d[] = 'annuler_depot';

            return $d;
        }

        $l = Livraison::find($i->sujet_id);
        $d = ['reprendre', 'changer_transporteur'];
        if ($l && ! in_array($l->statut, CircuitLivraison::TRANSPORTEUR_MODIFIABLE, true)) {
            $d[] = 'retour_entrepot';
        }
        $d[] = 'annuler_commande';

        return $d;
    }

    /** Applique la décision (sous transaction). $aPrevenir reçoit [utilisateur, message]. */
    private function appliquer(Incident $i, array $d, Utilisateur $auteur, array &$aPrevenir): ?JsonResponse
    {
        $motif = $d['motif'] ?? null;

        if ($i->sujet_type === 'reservation') {
            $r = Reservation::with('producteur.utilisateur', 'entrepot')->whereKey($i->sujet_id)->lockForUpdate()->first();

            if ($d['decision'] === 'changer_transporteur') {
                if ($refus = $this->transporteurIndisponible($d['transporteur_id'])) {
                    return $refus;
                }
                $ancien             = $r->transporteur_id;
                $r->transporteur_id = (int) $d['transporteur_id'];
                // Avant l'arrivée chez le producteur, la nouvelle mission est à accepter ;
                // après (panne en route), la marchandise est transférée : mission imposée.
                if (in_array($r->statut, CircuitDepot::TRANSPORTEUR_MODIFIABLE, true)) {
                    $r->statut              = 'confirmee';
                    $r->mission_acceptee_le = null;
                } else {
                    $r->mission_acceptee_le = now();
                }
                $r->save();
                HistoriqueOperation::consigner('reservation', $r->id, 'reassignation_transporteur', $auteur, null, $r->statut, $motif,
                    ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $r->transporteur_id]);
                $aPrevenir[] = [$this->utilisateurTransporteur($r->transporteur_id), sprintf(
                    'Mission %s confiée suite à un incident : %s de chez le producteur vers l\'entrepôt %s.',
                    $r->numero_reservation, $r->produit, $r->entrepot?->nom_entrepot,
                )];
                if ($ancien && $ancien !== $r->transporteur_id) {
                    $aPrevenir[] = [$this->utilisateurTransporteur($ancien), sprintf('La mission %s vous a été retirée suite à l\'incident.', $r->numero_reservation)];
                }
            } elseif ($d['decision'] === 'annuler_depot') {
                $ancien    = $r->statut;
                $r->statut = 'annulee';
                $r->save();
                HistoriqueOperation::consigner('reservation', $r->id, 'annulation', $auteur, $ancien, 'annulee', $motif);
                $aPrevenir[] = [$r->producteur?->utilisateur, sprintf('Le dépôt %s est annulé par l\'entrepôt suite à un incident. Motif : %s', $r->numero_reservation, $motif)];
            }

            return null;
        }

        $c = $i->sujet_type === 'commande'
            ? Commande::with('acheteur.utilisateur')->whereKey($i->sujet_id)->lockForUpdate()->first()
            : Commande::with('acheteur.utilisateur')->whereKey(Livraison::find($i->sujet_id)?->commande_id)->lockForUpdate()->first();
        $acheteur = $c?->acheteur?->utilisateur;

        switch ($d['decision']) {
            case 'reprendre':
                $aPrevenir[] = [$acheteur, sprintf('La livraison de votre commande %s reprend.', $c?->numero_commande)];
                break;

            case 'changer_transporteur':
                if ($refus = $this->transporteurIndisponible($d['transporteur_id'])) {
                    return $refus;
                }
                $l = Livraison::whereKey($i->sujet_id)->lockForUpdate()->first();
                if (in_array($l->statut, CircuitLivraison::TRANSPORTEUR_MODIFIABLE, true)) {
                    $ancien = app(LivraisonController::class)->affecter($l, (int) $d['transporteur_id'], $auteur, $motif);
                } else {
                    // Marchandise déjà chargée : transfert vers le nouveau véhicule, qui repart (mission imposée).
                    $ancien                 = $l->transporteur_id;
                    $ancienStatut           = $l->statut;
                    $l->transporteur_id     = (int) $d['transporteur_id'];
                    $l->statut              = 'chargee';
                    $l->mission_acceptee_le = now();
                    $l->save();
                    HistoriqueOperation::consigner('livraison', $l->id, 'reassignation_transporteur', $auteur, $ancienStatut, 'chargee', $motif,
                        ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $l->transporteur_id, 'transfert' => true]);
                }
                $aPrevenir[] = [$this->utilisateurTransporteur($l->transporteur_id), sprintf(
                    'Livraison %s confiée suite à un incident : commande %s vers %s.', $l->numero_livraison, $c?->numero_commande, $l->destination,
                )];
                if ($ancien && $ancien !== $l->transporteur_id) {
                    $aPrevenir[] = [$this->utilisateurTransporteur($ancien), sprintf('La livraison %s vous a été retirée suite à l\'incident.', $l->numero_livraison)];
                }
                break;

            case 'retour_entrepot':
                // La marchandise revient : elle rentre en stock, réservée de nouveau pour la commande, qui redevient prête.
                $l      = Livraison::whereKey($i->sujet_id)->lockForUpdate()->first();
                $ancien = $l->statut;
                $l->statut = 'retournee';
                $l->save();
                HistoriqueOperation::consigner('livraison', $l->id, 'retour_entrepot', $auteur, $ancien, 'retournee', $motif);
                if ($c->etat_stock === 'sorti' && $c->stock_id) {
                    $this->gestionStock->retourner($c->stock_id, (float) $c->quantite, $auteur, 'commande', $c->id, $motif);
                    $this->gestionStock->reserver($c->stock_id, (float) $c->quantite, $auteur, 'commande', $c->id);
                    $c->etat_stock = 'reserve';
                }
                $ancienC           = $c->statut;
                $c->statut         = 'prete';
                $c->code_reception = null;
                $c->save();
                HistoriqueOperation::consigner('commande', $c->id, 'retour_entrepot', $auteur, $ancienC, 'prete', $motif);
                $aPrevenir[] = [$acheteur, sprintf('Votre commande %s revient à l\'entrepôt, qui va organiser une nouvelle livraison.', $c->numero_commande)];
                break;

            case 'annuler_commande':
            case 'accepter_annulation':
            case 'reprendre_marchandise':
                if ($d['decision'] === 'accepter_annulation' && ! in_array('annuler_commande', CircuitLivraison::actionsCommande($c->load('livraison', 'stock'), $auteur), true)) {
                    return response()->json(['message' => 'La commande est déjà chargée : l\'annulation n\'est plus possible, refusez la demande.', 'statut_actuel' => $c->statut], 422);
                }
                $transporteur = $this->annulation->annuler($c, $auteur, $d['decision'] === 'accepter_annulation' ? 'annulation_acceptee' : 'annulation_incident', $motif, true);
                $c->code_reception = null;
                $c->save();
                $aPrevenir[] = [$acheteur, sprintf('Votre commande %s est annulée par l\'entrepôt.%s', $c->numero_commande, $motif ? ' Motif : '.$motif : '')];
                if ($transporteur) {
                    $aPrevenir[] = [$this->utilisateurTransporteur($transporteur), sprintf('La livraison de la commande %s est annulée.', $c->numero_commande)];
                }
                break;

            case 'refuser_annulation':
                $aPrevenir[] = [$acheteur, sprintf('Votre demande d\'annulation de la commande %s est refusée. Motif : %s', $c->numero_commande, $motif)];
                break;

            case 'cloturer_litige':
                $c->statut = 'receptionnee';
                $c->save();
                HistoriqueOperation::consigner('commande', $c->id, 'cloture_litige', $auteur, 'litige', 'receptionnee', $motif);
                break;
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function peutDecider(Incident $i, Utilisateur $u): bool
    {
        if ($u->role === 'administrateur') {
            return true;
        }

        $entrepotId = $u->entrepot?->id;

        return $entrepotId !== null && $this->entrepotId($i) === (int) $entrepotId;
    }

    private function entrepotId(Incident $i): ?int
    {
        return match ($i->sujet_type) {
            'reservation' => Reservation::find($i->sujet_id)?->entrepot_id,
            'livraison'   => Livraison::with('commande.stock')->find($i->sujet_id)?->commande?->stock?->entrepot_id,
            default       => Commande::with('stock')->find($i->sujet_id)?->stock?->entrepot_id,
        };
    }

    private function gestionnaire(Incident $i): ?Utilisateur
    {
        $entrepotId = $this->entrepotId($i);

        return $entrepotId ? \App\Models\Entrepot::with('utilisateur')->find($entrepotId)?->utilisateur : null;
    }

    private function resumeSujet(Incident $i): array
    {
        return match ($i->sujet_type) {
            'reservation' => ['numero' => Reservation::find($i->sujet_id)?->numero_reservation],
            'livraison'   => (function () use ($i) {
                $l = Livraison::with('commande:id,numero_commande,produit')->find($i->sujet_id);

                return ['numero' => $l?->numero_livraison, 'statut' => $l?->statut, 'commande' => $l?->commande?->numero_commande, 'produit' => $l?->commande?->produit];
            })(),
            default       => (function () use ($i) {
                $c = Commande::find($i->sujet_id);

                return ['numero' => $c?->numero_commande, 'statut' => $c?->statut, 'produit' => $c?->produit];
            })(),
        };
    }

    private function transporteurIndisponible(mixed $id): ?JsonResponse
    {
        return Transporteur::with('utilisateur')->find($id)?->utilisateur?->statut === 'bloque'
            ? response()->json(['message' => 'Ce transporteur ne peut pas recevoir de mission (compte bloqué).'], 422)
            : null;
    }

    private function utilisateurTransporteur(?int $id): ?Utilisateur
    {
        return $id ? Transporteur::with('utilisateur')->find($id)?->utilisateur : null;
    }

    private function notifier(?Utilisateur $u, Incident $i, string $message): void
    {
        $u?->notify(new OperationModifieeNotification('incident', $message, [
            'incident_id' => $i->id, 'sujet_type' => $i->sujet_type, 'sujet_id' => $i->sujet_id,
        ]));
    }

    private function libelle(?string $code): string
    {
        return [
            'panne' => 'panne', 'retard' => 'retard', 'acces_impossible' => 'accès impossible', 'acheteur_absent' => 'acheteur absent',
            'marchandise_abimee' => 'marchandise abîmée', 'autre' => 'autre problème', 'demande_annulation' => 'demande d\'annulation',
            'quantite_manquante' => 'quantité manquante', 'produit_abime' => 'produit abîmé', 'livraison_incorrecte' => 'livraison incorrecte',
            'reprendre' => 'la livraison reprend', 'changer_transporteur' => 'un autre transporteur prend le relais',
            'retour_entrepot' => 'retour à l\'entrepôt', 'annuler_commande' => 'commande annulée', 'annuler_depot' => 'dépôt annulé',
            'accepter_annulation' => 'annulation acceptée', 'refuser_annulation' => 'annulation refusée',
            'cloturer_litige' => 'litige clos', 'reprendre_marchandise' => 'marchandise reprise et commande annulée',
        ][$code] ?? (string) $code;
    }
}
