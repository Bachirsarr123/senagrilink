<?php

namespace App\Support;

use App\Models\PositionGps;
use App\Models\Transporteur;
use Illuminate\Support\Collection;

/**
 * Transporteur proposé d'office au gestionnaire (lot 9) : un appui suffit pour
 * confier la mission. Libres d'abord, puis le plus proche du lieu de prise en
 * charge (dernière position connue de ses trajets), puis le moins occupé.
 */
final class PropositionTransporteur
{
    private const ACHEMINEMENTS_ACTIFS = ['confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'];
    private const LIVRAISONS_ACTIVES   = ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee'];

    /** Transporteurs classés, avec missions en cours et distance au lieu de prise en charge (si connue). */
    public static function classer(?float $lat, ?float $lng): Collection
    {
        $transporteurs = Transporteur::with('utilisateur:id,nom,prenom,telephone,statut')
            ->whereHas('utilisateur', fn ($q) => $q->where('statut', 'actif'))
            ->withCount([
                'reservations as acheminements_actifs' => fn ($q) => $q->whereIn('statut', self::ACHEMINEMENTS_ACTIFS),
                'livraisons as livraisons_actives'      => fn ($q) => $q->whereIn('statut', self::LIVRAISONS_ACTIVES),
            ])
            ->get();

        $positions = PositionGps::whereIn('utilisateur_id', $transporteurs->pluck('utilisateur_id'))
            ->where('timestamp', '>=', now()->subDays(7))
            ->orderByDesc('timestamp')->get(['utilisateur_id', 'latitude', 'longitude', 'timestamp'])
            ->unique('utilisateur_id')->keyBy('utilisateur_id');

        return $transporteurs->map(function (Transporteur $t) use ($positions, $lat, $lng) {
            $p = $positions->get($t->utilisateur_id);
            $t->setAttribute('missions_actives', $t->acheminements_actifs + $t->livraisons_actives);
            $t->setAttribute('distance_km', $p && $lat !== null && $lng !== null
                ? round(SuiviGps::distanceKm($lat, $lng, (float) $p->latitude, (float) $p->longitude), 1) : null);

            return $t;
        })->sortBy([
            fn ($a, $b) => ($a->missions_actives > 0) <=> ($b->missions_actives > 0),
            fn ($a, $b) => ($a->distance_km ?? INF) <=> ($b->distance_km ?? INF),
            fn ($a, $b) => $a->missions_actives <=> $b->missions_actives,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
    }

    /** Le meilleur, résumé pour un bouton « Confier à … ». */
    public static function meilleur(?float $lat, ?float $lng): ?array
    {
        $t = self::classer($lat, $lng)->first();

        return $t ? [
            'id' => $t->id, 'nom' => trim(($t->utilisateur?->prenom ?? '').' '.($t->utilisateur?->nom ?? '')),
            'telephone' => $t->utilisateur?->telephone, 'type_vehicule' => $t->type_vehicule,
            'missions_actives' => $t->missions_actives, 'distance_km' => $t->distance_km,
        ] : null;
    }
}
