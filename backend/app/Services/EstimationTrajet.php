<?php

namespace App\Services;

use App\Models\PositionGps;
use App\Support\SuiviGps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Heure d'arrivée estimée et trajet routier. Avec un service de routage
 * compatible OSRM (services.routage.url), le trajet suit les routes ; sinon,
 * distance à vol d'oiseau × 1,3 et vitesse récente du véhicule (ou vitesse par
 * défaut). Recalcul au plus une fois par minute ; l'estimation est gardée sur
 * la mission pour que tous les acteurs voient la même.
 */
class EstimationTrajet
{
    public const INTERVALLE_S = 60;
    public const FACTEUR_ROUTE = 1.3;

    public function actualiser(Model $mission, PositionGps $position, bool $forcer = false): void
    {
        if (! $forcer && $mission->estimation_le && $mission->estimation_le->diffInSeconds(now()) < self::INTERVALLE_S) {
            return;
        }

        $dest = SuiviGps::extremites($mission)['destination'];

        if ($dest['latitude'] === null || $dest['longitude'] === null) {
            $mission->forceFill(['estimation_le' => now(), 'arrivee_estimee_le' => null, 'distance_restante_km' => null, 'trajet' => null])->save();

            return;
        }

        $route = $this->routage($position->latitude, $position->longitude, (float) $dest['latitude'], (float) $dest['longitude']);

        if ($route) {
            [$km, $secondes, $trajet] = $route;
        } else {
            $km       = SuiviGps::distanceKm($position->latitude, $position->longitude, (float) $dest['latitude'], (float) $dest['longitude']) * self::FACTEUR_ROUTE;
            $secondes = $km / $this->vitesse($mission) * 3600;
            $trajet   = null;
        }

        $mission->forceFill([
            'distance_restante_km' => round($km, 1),
            'arrivee_estimee_le'   => now()->addSeconds((int) round($secondes)),
            'trajet'               => $trajet,
            'estimation_le'        => now(),
        ])->save();
    }

    /** [km, secondes, [[lat, lng], …]] depuis le service de routage, ou null. */
    private function routage(float $lat, float $lng, float $dLat, float $dLng): ?array
    {
        $base = config('services.routage.url');

        if (! $base) {
            return null;
        }

        try {
            $reponse = Http::timeout(config('services.routage.delai_s', 4))
                ->get(rtrim($base, '/')."/route/v1/driving/{$lng},{$lat};{$dLng},{$dLat}", ['overview' => 'simplified', 'geometries' => 'geojson']);
            $route = $reponse->successful() ? ($reponse->json('routes.0') ?? null) : null;

            if (! $route || ! isset($route['distance'], $route['duration'])) {
                return null;
            }

            $points = array_map(fn ($c) => [round($c[1], 6), round($c[0], 6)], $route['geometry']['coordinates'] ?? []);

            return [$route['distance'] / 1000, $route['duration'], array_slice($points, 0, 500)];
        } catch (Throwable) {
            return null;
        }
    }

    /** Vitesse moyenne récente (km/h) ; vitesse par défaut si le véhicule est arrêté ou inconnu. */
    private function vitesse(Model $mission): float
    {
        $recente = PositionGps::where('sujet_type', SuiviGps::type($mission))->where('sujet_id', $mission->id)
            ->whereNotNull('vitesse_kmh')->where('timestamp', '>=', now()->subMinutes(10))->avg('vitesse_kmh');

        return $recente !== null && $recente >= 10 ? (float) $recente : (float) config('services.suivi.vitesse_defaut_kmh', 40);
    }
}
