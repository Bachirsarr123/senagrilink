<?php

namespace App\Support;

use App\Models\Livraison;
use App\Models\Reservation;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;

/**
 * Définition unique du suivi GPS des deux circuits (cahier des charges v2, Q11) :
 *  - livraison (entrepôt → acheteur), suivie pendant « en_cours » ;
 *  - acheminement (producteur → entrepôt), suivi pendant « en_route ».
 * La position n'est envoyée que par la personne qui conduit et n'est visible que
 * des acteurs de cette mission, et seulement pendant le trajet.
 */
final class SuiviGps
{
    public const TYPES = ['livraison', 'reservation'];

    /** Statut « en route », pendant lequel la position est partagée. */
    public const EN_ROUTE = ['livraison' => 'en_cours', 'reservation' => 'en_route'];

    /** Statuts après l'arrivée : les positions prises hors connexion pendant le trajet restent acceptées. */
    public const APRES_ARRIVEE = ['livraison' => ['arrivee', 'livree'], 'reservation' => ['arrivee_entrepot', 'enregistree']];

    public static function trouver(string $type, int $id): ?Model
    {
        return match ($type) {
            'livraison'   => Livraison::with(['commande.acheteur.utilisateur', 'commande.stock.entrepot', 'commande.stock.production', 'transporteur.utilisateur'])->find($id),
            'reservation' => Reservation::with(['entrepot', 'production', 'producteur.utilisateur', 'transporteur.utilisateur'])->find($id),
            default       => null,
        };
    }

    public static function type(Model $m): string
    {
        return $m instanceof Livraison ? 'livraison' : 'reservation';
    }

    public static function enRoute(Model $m): bool
    {
        return $m->statut === self::EN_ROUTE[self::type($m)];
    }

    /** Personne qui conduit et envoie la position. */
    public static function estConducteur(Model $m, Utilisateur $u): bool
    {
        if ($m instanceof Reservation) {
            return CircuitDepot::estConducteur($m, $u);
        }

        return $u->transporteur !== null && (int) $u->transporteur->id === (int) $m->transporteur_id && $m->mission_acceptee_le !== null;
    }

    /** Acteurs de la mission : conducteur, destinataire, producteur du lot, gestionnaire de l'entrepôt, administrateur. */
    public static function peutVoir(Model $m, Utilisateur $u): bool
    {
        if ($u->role === 'administrateur' || self::estConducteur($m, $u)) {
            return true;
        }

        if ($m instanceof Reservation) {
            return ($u->producteur && (int) $u->producteur->id === (int) $m->producteur_id)
                || ($u->entrepot && (int) $u->entrepot->id === (int) $m->entrepot_id);
        }

        $c = $m->commande;

        return ($u->acheteurGros && (int) $u->acheteurGros->id === (int) $c?->acheteur_id)
            || ($u->producteur && (int) $u->producteur->id === (int) $c?->stock?->production?->producteur_id)
            || ($u->entrepot && (int) $u->entrepot->id === (int) $c?->stock?->entrepot_id);
    }

    /** Point de départ et d'arrivée de la mission (coordonnées éventuellement absentes). */
    public static function extremites(Model $m): array
    {
        if ($m instanceof Reservation) {
            $p = $m->producteur;
            $e = $m->entrepot;

            return [
                'origine'     => ['nom' => trim(($p?->utilisateur?->prenom ?? '').' '.($p?->utilisateur?->nom ?? '')) ?: 'Producteur', 'adresse' => $p?->utilisateur?->adresse, 'latitude' => $p?->latitude, 'longitude' => $p?->longitude],
                'destination' => ['nom' => $e?->nom_entrepot, 'adresse' => $e?->localisation, 'latitude' => $e?->latitude, 'longitude' => $e?->longitude],
            ];
        }

        $e = $m->commande?->stock?->entrepot;
        $a = $m->commande?->acheteur;

        return [
            'origine'     => ['nom' => $e?->nom_entrepot, 'adresse' => $e?->localisation, 'latitude' => $e?->latitude, 'longitude' => $e?->longitude],
            'destination' => [
                'nom'       => trim(($a?->utilisateur?->prenom ?? '').' '.($a?->utilisateur?->nom ?? '')) ?: 'Acheteur',
                'adresse'   => $m->destination,
                'latitude'  => $m->destination_latitude ?? $a?->latitude,
                'longitude' => $m->destination_longitude ?? $a?->longitude,
            ],
        ];
    }

    /** Personne qui conduit (nom et téléphone, visibles des acteurs de la mission). */
    public static function conducteur(Model $m): ?array
    {
        $u = $m instanceof Reservation && $m->mode_transport === CircuitDepot::MODE_PRODUCTEUR
            ? $m->producteur?->utilisateur
            : $m->transporteur?->utilisateur;

        return $u ? ['nom' => trim($u->prenom.' '.$u->nom), 'telephone' => $u->telephone] : null;
    }

    /** Repères de la mission, visibles même sans position (marchandise, lot, heures). */
    public static function details(Model $m): array
    {
        $commande = $m instanceof Livraison ? $m->commande : null;

        return [
            'produit'          => $commande?->produit ?? $m->produit,
            'quantite'         => $commande?->quantite ?? $m->quantite_reservee,
            'code_tracabilite' => $m instanceof Livraison ? $commande?->stock?->production?->code_tracabilite : $m->production?->code_tracabilite,
            'parti_le'         => $m->parti_le?->toIso8601String(),
            'arrive_le'        => $m->arrive_le?->toIso8601String(),
        ];
    }

    public static function numero(Model $m): string
    {
        return $m instanceof Livraison ? $m->numero_livraison : $m->numero_reservation;
    }

    /** Distance à vol d'oiseau, en kilomètres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
