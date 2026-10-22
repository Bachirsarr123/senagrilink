<?php

namespace App\Support;

use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Page « À faire » (lot 5) : pour la personne connectée, ce qui attend une
 * action de sa part, tiré des mêmes règles que les circuits. Un élément y
 * figure quand l'étape attendue revient à son rôle et qu'elle a une action
 * possible ; les incidents à décider et les stocks en alerte s'y ajoutent.
 * Plus ancien et plus urgent d'abord.
 */
final class AFaire
{
    /** Au-delà, l'élément est signalé comme en retard. */
    public const RETARD_HEURES = 24;

    public static function pour(Utilisateur $u): array
    {
        $elements = collect()
            ->merge(self::depots($u))
            ->merge(self::commandes($u))
            ->merge(self::livraisons($u))
            ->merge(self::incidents($u))
            ->merge(self::stocks($u))
            ->merge(self::lieuEntrepot($u))
            ->map(function (array $e) {
                $e['en_retard'] = $e['depuis'] !== null && Carbon::parse($e['depuis'])->diffInHours(now(), true) >= self::RETARD_HEURES;
                $e['urgence']   = $e['urgence'] ?? ($e['en_retard'] ? 'haute' : 'normale');

                return $e;
            })
            ->sortBy([fn ($a, $b) => ($a['urgence'] === 'haute' ? 0 : 1) <=> ($b['urgence'] === 'haute' ? 0 : 1), fn ($a, $b) => ($a['depuis'] ?? '') <=> ($b['depuis'] ?? '')])
            ->values();

        return $elements->all();
    }

    private static function depots(Utilisateur $u): Collection
    {
        $q = Reservation::with(['production:id,code_tracabilite', 'incidents' => fn ($q) => $q->ouverts()])
            ->whereNotIn('statut', ['enregistree', 'refusee', 'annulee', 'reception_refusee']);

        if ($u->producteur) {
            $q->where('producteur_id', $u->producteur->id);
        } elseif ($u->entrepot) {
            $q->where('entrepot_id', $u->entrepot->id);
        } elseif ($u->transporteur) {
            $q->where('transporteur_id', $u->transporteur->id);
        } else {
            return collect();
        }

        return $q->get()->filter(function (Reservation $r) use ($u) {
            $bloque  = $r->incidents->contains(fn (Incident $i) => $i->estBloquant());
            $etape   = CircuitDepot::prochaineEtape($r);
            $actions = array_diff(CircuitDepot::actions($r, $u, $bloque), ['annuler', 'declarer_incident', 'remettre_en_attente', 'retirer_transporteur']);

            return $etape && $etape['acteur'] === $u->role && $actions !== [];
        })->map(fn (Reservation $r) => self::avecAction($r, array_diff(
            CircuitDepot::actions($r, $u, $r->incidents->contains(fn (Incident $i) => $i->estBloquant())),
            ['annuler', 'declarer_incident', 'remettre_en_attente', 'retirer_transporteur', 'refuser', 'refuser_mission', 'refuser_reception'],
        ), [
            'categorie'  => 'depot',
            'sujet_type' => 'reservation',
            'sujet_id'   => $r->id,
            'numero'     => $r->numero_reservation,
            'titre'      => sprintf('%s — %s kg', $r->produit, rtrim(rtrim(number_format((float) $r->quantite_reservee, 2, ',', ' '), '0'), ',')),
            'etape'      => CircuitDepot::prochaineEtape($r)['libelle'],
            'depuis'     => self::derniereEtape('reservation', $r->id, $r->date_reservation),
        ]));
    }

    private static function commandes(Utilisateur $u): Collection
    {
        if (! $u->acheteurGros && ! $u->entrepot) {
            return collect();
        }

        $q = Commande::with(['stock:id,entrepot_id', 'stock.entrepot:id,latitude,longitude', 'livraison', 'incidents'])
            ->whereNotIn('statut', ['receptionnee', 'annulee']);
        $u->acheteurGros
            ? $q->where('acheteur_id', $u->acheteurGros->id)
            : $q->whereHas('stock', fn ($s) => $s->where('entrepot_id', $u->entrepot->id));

        return $q->get()->filter(function (Commande $c) use ($u) {
            $etape   = CircuitLivraison::prochaineEtape($c);
            $actions = array_diff(CircuitLivraison::actionsCommande($c, $u), ['modifier', 'annuler', 'demander_annulation', 'annuler_commande', 'refuser']);

            return $etape && $etape['acteur'] === $u->role && $actions !== [];
        })->map(fn (Commande $c) => self::avecAction($c, array_diff(
            CircuitLivraison::actionsCommande($c, $u),
            ['modifier', 'annuler', 'demander_annulation', 'annuler_commande', 'refuser', 'changer_transporteur', 'declarer_anomalie'],
        ), [
            'categorie'  => 'commande',
            'sujet_type' => 'commande',
            'sujet_id'   => $c->id,
            'numero'     => $c->numero_commande,
            'titre'      => sprintf('%s — %s kg', $c->produit, rtrim(rtrim(number_format((float) $c->quantite, 2, ',', ' '), '0'), ',')),
            'etape'      => CircuitLivraison::prochaineEtape($c)['libelle'],
            'depuis'     => self::derniereEtape('commande', $c->id, $c->date_commande),
        ]));
    }

    private static function livraisons(Utilisateur $u): Collection
    {
        if (! $u->transporteur) {
            return collect();
        }

        return Livraison::with(['commande', 'incidents' => fn ($q) => $q->ouverts()])
            ->where('transporteur_id', $u->transporteur->id)
            ->whereIn('statut', ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee'])
            ->get()
            ->filter(function (Livraison $l) use ($u) {
                $bloque  = $l->incidents->contains(fn (Incident $i) => $i->estBloquant());
                $actions = array_diff(CircuitLivraison::actionsLivraison($l, $u, $bloque), ['declarer_incident', 'refuser_mission']);

                return $actions !== [];
            })
            ->map(fn (Livraison $l) => self::avecAction($l, array_diff(
                CircuitLivraison::actionsLivraison($l, $u, $l->incidents->contains(fn (Incident $i) => $i->estBloquant())),
                ['declarer_incident', 'refuser_mission'],
            ), [
                'categorie'  => 'livraison',
                'sujet_type' => 'livraison',
                'sujet_id'   => $l->id,
                'numero'     => $l->numero_livraison,
                'titre'      => sprintf('%s — %s kg vers %s', $l->commande?->produit, rtrim(rtrim(number_format((float) $l->commande?->quantite, 2, ',', ' '), '0'), ','), $l->destination),
                'etape'      => CircuitLivraison::prochaineEtape($l->commande->setRelation('livraison', $l))['libelle'] ?? 'Mission en cours',
                'depuis'     => self::derniereEtape('livraison', $l->id, null),
                'urgence'    => $l->statut === 'en_attente' ? null : 'haute',
            ]));
    }

    /** Incidents ouverts à décider par le gestionnaire de l'entrepôt concerné. */
    private static function incidents(Utilisateur $u): Collection
    {
        if (! $u->entrepot) {
            return collect();
        }

        $entrepotId = $u->entrepot->id;
        $commandes  = Commande::whereHas('stock', fn ($s) => $s->where('entrepot_id', $entrepotId))->select('id');

        return Incident::ouverts()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('sujet_type', 'commande')->whereIn('sujet_id', $commandes))
                ->orWhere(fn ($q) => $q->where('sujet_type', 'livraison')->whereIn('sujet_id', Livraison::whereIn('commande_id', $commandes)->select('id')))
                ->orWhere(fn ($q) => $q->where('sujet_type', 'reservation')->whereIn('sujet_id', Reservation::where('entrepot_id', $entrepotId)->select('id'))))
            ->get()
            ->map(fn (Incident $i) => [
                'categorie'  => 'incident',
                'sujet_type' => $i->sujet_type,
                'sujet_id'   => $i->sujet_id,
                'incident_id' => $i->id,
                'numero'     => match ($i->sujet_type) {
                    'commande'    => Commande::find($i->sujet_id)?->numero_commande,
                    'livraison'   => Livraison::find($i->sujet_id)?->numero_livraison,
                    default       => Reservation::find($i->sujet_id)?->numero_reservation,
                },
                'titre'      => $i->description,
                'etape'      => 'Incident à décider : '.Incident::libelle($i->type),
                'type_incident' => $i->type,
                'depuis'     => $i->created_at?->toIso8601String(),
                'urgence'    => $i->estBloquant() || $i->type === 'demande_annulation' ? 'haute' : null,
            ]);
    }

    private static function stocks(Utilisateur $u): Collection
    {
        if (! $u->entrepot) {
            return collect();
        }

        return Stock::where('entrepot_id', $u->entrepot->id)
            ->whereNotNull('seuil_alerte')
            ->whereRaw('(quantite - quantite_reservee) <= seuil_alerte')
            ->where('statut', '!=', 'epuise')
            ->get()
            ->map(fn (Stock $s) => [
                'categorie'  => 'stock',
                'sujet_type' => 'stock',
                'sujet_id'   => $s->id,
                'numero'     => $s->produit,
                'titre'      => sprintf('%s : %s kg disponibles (seuil %s kg)', $s->produit, (float) $s->quantite_disponible, (float) $s->seuil_alerte),
                'etape'      => 'Stock sous le seuil d\'alerte',
                'depuis'     => null,
                'urgence'    => 'normale',
            ]);
    }

    /**
     * Lot 9 : l'action principale se fait depuis « À faire », d'un appui. Pour
     * confier une mission, le transporteur libre le plus proche est proposé.
     * Lot 10 : « accepter_et_confier » (dépôt) et « prete_et_confier » (commande)
     * enchaînent les étapes côté client, chacune restant datée dans l'historique.
     * Les actions qui demandent un motif ou une saisie (refus, réception, code)
     * restent sur l'écran de la mission.
     */
    private static function avecAction(object $sujet, array $actions, array $element): array
    {
        $action = collect($actions)->first(fn ($a) => in_array($a, self::ACTIONS_DIRECTES, true));

        // Lot 10 : étapes regroupées en un appui quand un transporteur libre peut être proposé
        $groupee = match (true) {
            $sujet instanceof Reservation && $action === 'confirmer' && $sujet->mode_transport !== CircuitDepot::MODE_PRODUCTEUR => 'accepter_et_confier',
            $sujet instanceof Commande && in_array($action, ['preparer', 'marquer_prete'], true) => 'prete_et_confier',
            default => null,
        };
        $element['action'] = $groupee ?? $action;
        $element['statut'] = $sujet->statut;

        if (in_array($element['action'], ['assigner', 'organiser_livraison', 'accepter_et_confier', 'prete_et_confier'], true)) {
            $depart = $sujet instanceof Reservation
                ? SuiviGps::extremites($sujet->loadMissing('producteur.utilisateur', 'entrepot'))['origine']
                : ['latitude' => $sujet->stock?->entrepot?->latitude, 'longitude' => $sujet->stock?->entrepot?->longitude];
            $propose = PropositionTransporteur::meilleur(
                $depart['latitude'] !== null ? (float) $depart['latitude'] : null,
                $depart['longitude'] !== null ? (float) $depart['longitude'] : null,
            );
            $element['transporteur_propose'] = $propose;
            // Aucun transporteur disponible : l'étape simple, sans regroupement
            if ($propose === null && $groupee !== null) {
                $element['action'] = $action;
            }
        }

        return $element;
    }

    /** Actions faisables d'un appui (sans motif ni saisie). */
    private const ACTIONS_DIRECTES = [
        'confirmer', 'assigner', 'accepter_mission', 'arriver_collecte', 'charger', 'partir', 'arriver',
        'preparer', 'marquer_prete', 'organiser_livraison',
    ];

    /** Entrepôt sans emplacement sur la carte : les transporteurs ne sauraient pas où aller. */
    private static function lieuEntrepot(Utilisateur $u): Collection
    {
        $e = $u->entrepot;
        if (! $e || ($e->latitude !== null && $e->longitude !== null)) {
            return collect();
        }

        return collect([[
            'categorie'  => 'lieu',
            'sujet_type' => 'entrepot',
            'sujet_id'   => $e->id,
            'numero'     => $e->nom_entrepot,
            'titre'      => 'Placez votre entrepôt sur la carte',
            'etape'      => 'Les transporteurs en ont besoin pour venir charger et livrer',
            'depuis'     => null,
            'urgence'    => 'haute',
        ]]);
    }

    /** Date de la dernière étape historisée (ou de création). */
    private static function derniereEtape(string $type, int $id, mixed $repli): ?string
    {
        $date = HistoriqueOperation::where('sujet_type', $type)->where('sujet_id', $id)->max('created_at') ?? $repli;

        return $date ? Carbon::parse($date)->toIso8601String() : null;
    }
}
