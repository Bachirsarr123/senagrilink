<?php

namespace App\Services;

use App\Support\SuiviGps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Itinéraire routier avec consignes en français (lot 9), pour guider le
 * conducteur dans l'application et montrer le chemin aux autres acteurs.
 *
 * Fournisseurs : OpenRouteService (ROUTAGE_CLE) ou un service compatible OSRM
 * (ROUTAGE_URL). Sans eux, ligne droite et une seule consigne de direction.
 * Les réponses sont gardées 2 minutes par position arrondie (~50 m) et
 * destination, pour rester dans les quotas gratuits.
 */
class Itineraire
{
    /** Nombre maximal de points renvoyés (la géométrie est allégée au-delà). */
    public const POINTS_MAX = 2500;

    /**
     * @return array{km: float, secondes: float, points: list<array{0: float, 1: float}>, etapes: list<array>, routier: bool}
     */
    public function calculer(float $lat, float $lng, float $dLat, float $dLng): array
    {
        $cle = sprintf('itineraire:%s:%.4f,%.4f:%.5f,%.5f', $this->fournisseur() ?? 'aucun', $lat, $lng, $dLat, $dLng);

        $route = Cache::remember($cle, 120, fn () => match ($this->fournisseur()) {
            'ors'  => $this->ors($lat, $lng, $dLat, $dLng),
            'osrm' => $this->osrm($lat, $lng, $dLat, $dLng),
            default => null,
        } ?? false);

        return $route ?: $this->ligneDroite($lat, $lng, $dLat, $dLng);
    }

    public function fournisseur(): ?string
    {
        $choix = config('services.routage.fournisseur');
        if ($choix) {
            return $choix;
        }

        return config('services.routage.cle') ? 'ors' : (config('services.routage.url') ? 'osrm' : null);
    }

    private function ors(float $lat, float $lng, float $dLat, float $dLng): ?array
    {
        try {
            $reponse = Http::timeout(config('services.routage.delai_s', 4))
                ->withHeaders(['Authorization' => (string) config('services.routage.cle')])
                ->post(rtrim(config('services.routage.url') ?: 'https://api.openrouteservice.org', '/').'/v2/directions/driving-car/geojson', [
                    'coordinates' => [[$lng, $lat], [$dLng, $dLat]],
                    'language' => 'fr', 'instructions' => true, 'units' => 'm',
                ]);
            $f = $reponse->successful() ? $reponse->json('features.0') : null;
            if (! $f || ! isset($f['properties']['summary'])) {
                return null;
            }

            $points = array_map(fn ($c) => [round($c[1], 6), round($c[0], 6)], $f['geometry']['coordinates'] ?? []);
            $etapes = [];
            foreach ($f['properties']['segments'][0]['steps'] ?? [] as $s) {
                $etapes[] = [
                    'instruction' => (string) ($s['instruction'] ?? ''),
                    'type'        => self::TYPES_ORS[$s['type'] ?? -1] ?? 'tout_droit',
                    'distance_m'  => (int) round($s['distance'] ?? 0),
                    'duree_s'     => (int) round($s['duration'] ?? 0),
                    'debut'       => (int) ($s['way_points'][0] ?? 0),
                    'fin'         => (int) ($s['way_points'][1] ?? 0),
                ];
            }

            return $this->resultat((float) ($f['properties']['summary']['distance'] ?? 0) / 1000, (float) ($f['properties']['summary']['duration'] ?? 0), $points, $etapes);
        } catch (Throwable) {
            return null;
        }
    }

    private function osrm(float $lat, float $lng, float $dLat, float $dLng): ?array
    {
        try {
            $reponse = Http::timeout(config('services.routage.delai_s', 4))
                ->get(rtrim(config('services.routage.url'), '/')."/route/v1/driving/{$lng},{$lat};{$dLng},{$dLat}", ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'true']);
            $route = $reponse->successful() ? ($reponse->json('routes.0') ?? null) : null;
            if (! $route || ! isset($route['distance'], $route['duration'])) {
                return null;
            }

            $points = array_map(fn ($c) => [round($c[1], 6), round($c[0], 6)], $route['geometry']['coordinates'] ?? []);
            $etapes = [];
            $index = 0;
            foreach ($route['legs'][0]['steps'] ?? [] as $s) {
                $n = max(1, count($s['geometry']['coordinates'] ?? []) - 1);
                [$type, $texte] = self::consigneOsrm($s['maneuver'] ?? [], (string) ($s['name'] ?? ''));
                $etapes[] = [
                    'instruction' => $texte, 'type' => $type,
                    'distance_m' => (int) round($s['distance'] ?? 0), 'duree_s' => (int) round($s['duration'] ?? 0),
                    'debut' => $index, 'fin' => min($index + $n, max(0, count($points) - 1)),
                ];
                $index += $n;
            }

            return $this->resultat($route['distance'] / 1000, (float) $route['duration'], $points, $etapes);
        } catch (Throwable) {
            return null;
        }
    }

    /** Sans service de routage : ligne droite, une consigne de direction. */
    private function ligneDroite(float $lat, float $lng, float $dLat, float $dLng): array
    {
        $km = SuiviGps::distanceKm($lat, $lng, $dLat, $dLng) * EstimationTrajet::FACTEUR_ROUTE;

        return [
            'km' => round($km, 1),
            'secondes' => $km / (float) config('services.suivi.vitesse_defaut_kmh', 40) * 3600,
            'points' => [[$lat, $lng], [$dLat, $dLng]],
            'etapes' => [[
                'instruction' => sprintf('Dirigez-vous vers %s (%s à vol d\'oiseau)', self::cap($lat, $lng, $dLat, $dLng), self::distanceLisible(SuiviGps::distanceKm($lat, $lng, $dLat, $dLng) * 1000)),
                'type' => 'tout_droit', 'distance_m' => (int) round($km * 1000), 'duree_s' => 0, 'debut' => 0, 'fin' => 1,
            ]],
            'routier' => false,
        ];
    }

    private function resultat(float $km, float $secondes, array $points, array $etapes): array
    {
        // Géométrie trop longue : un point sur k, les consignes suivent
        $k = (int) ceil(count($points) / self::POINTS_MAX);
        if ($k > 1) {
            $dernier = end($points);
            $points = array_values(array_filter($points, fn ($i) => $i % $k === 0, ARRAY_FILTER_USE_KEY));
            $points[] = $dernier;
            $etapes = array_map(fn ($e) => ['debut' => intdiv($e['debut'], $k), 'fin' => min(count($points) - 1, (int) ceil($e['fin'] / $k))] + $e, $etapes);
        }

        return ['km' => round($km, 1), 'secondes' => $secondes, 'points' => $points, 'etapes' => $etapes, 'routier' => true];
    }

    /** Types de manœuvre OpenRouteService → type affiché (flèche). */
    private const TYPES_ORS = [
        0 => 'gauche', 1 => 'droite', 2 => 'gauche_serre', 3 => 'droite_serre', 4 => 'gauche_leger', 5 => 'droite_leger',
        6 => 'tout_droit', 7 => 'rond_point', 8 => 'rond_point', 9 => 'demi_tour', 10 => 'arrivee', 11 => 'depart',
        12 => 'gauche_leger', 13 => 'droite_leger',
    ];

    /** Consigne en français à partir d'une manœuvre OSRM (type, direction, nom de la voie). */
    public static function consigneOsrm(array $m, string $voie): array
    {
        $dir = $m['modifier'] ?? 'straight';
        $type = match (true) {
            ($m['type'] ?? '') === 'depart' => 'depart',
            ($m['type'] ?? '') === 'arrive' => 'arrivee',
            in_array($m['type'] ?? '', ['roundabout', 'rotary', 'roundabout turn'], true) => 'rond_point',
            $dir === 'uturn' => 'demi_tour',
            default => ['left' => 'gauche', 'right' => 'droite', 'sharp left' => 'gauche_serre', 'sharp right' => 'droite_serre',
                'slight left' => 'gauche_leger', 'slight right' => 'droite_leger'][$dir] ?? 'tout_droit',
        };
        $sur = $voie !== '' ? " sur {$voie}" : '';
        $texte = match ($type) {
            'depart' => 'Partez'.($voie !== '' ? " par {$voie}" : ''),
            'arrivee' => 'Vous êtes arrivé à destination',
            'rond_point' => isset($m['exit']) ? "Au rond-point, prenez la {$m['exit']}e sortie{$sur}" : "Prenez le rond-point{$sur}",
            'demi_tour' => 'Faites demi-tour'.$sur,
            'gauche' => 'Tournez à gauche'.$sur, 'droite' => 'Tournez à droite'.$sur,
            'gauche_serre' => 'Tournez franchement à gauche'.$sur, 'droite_serre' => 'Tournez franchement à droite'.$sur,
            'gauche_leger' => 'Serrez à gauche'.$sur, 'droite_leger' => 'Serrez à droite'.$sur,
            default => 'Continuez tout droit'.$sur,
        };

        return [$type, $texte];
    }

    public static function distanceLisible(float $metres): string
    {
        return $metres >= 1000 ? str_replace('.', ',', (string) round($metres / 1000, 1)).' km' : ((int) (round($metres / 10) * 10)).' m';
    }

    /** Direction approximative (« le nord », « l'est »…) du point de départ vers l'arrivée. */
    public static function cap(float $lat, float $lng, float $dLat, float $dLng): string
    {
        $y = sin(deg2rad($dLng - $lng)) * cos(deg2rad($dLat));
        $x = cos(deg2rad($lat)) * sin(deg2rad($dLat)) - sin(deg2rad($lat)) * cos(deg2rad($dLat)) * cos(deg2rad($dLng - $lng));
        $angle = (rad2deg(atan2($y, $x)) + 360) % 360;

        return ['le nord', 'le nord-est', 'l\'est', 'le sud-est', 'le sud', 'le sud-ouest', 'l\'ouest', 'le nord-ouest'][(int) round($angle / 45) % 8];
    }
}
