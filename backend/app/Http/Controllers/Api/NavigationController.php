<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Reservation;
use App\Services\Itineraire;
use App\Support\CircuitDepot;
use App\Support\CircuitLivraison;
use App\Support\SuiviGps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guidage du conducteur dans l'application (lot 9) : chemin par la route et
 * consignes en français, depuis sa position vers le prochain lieu (prise en
 * charge, puis destination), avec l'action à faire en arrivant. La position
 * envoyée ici sert au calcul seulement : elle n'est ni gardée ni montrée.
 */
class NavigationController extends Controller
{
    /** À moins de cette distance du but, l'application propose « Vous êtes arrivé ? ». */
    public const ARRIVEE_M = 150;

    public function itineraire(Request $request, string $type, int $id, Itineraire $itineraire): JsonResponse
    {
        $request->validate([
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $mission = SuiviGps::trouver($type, $id);
        if (! $mission) {
            return response()->json(['message' => 'Mission introuvable.'], 404);
        }
        if (! SuiviGps::estConducteur($mission, $request->user())) {
            return response()->json(['message' => 'Seule la personne qui conduit est guidée pour cette mission.'], 403);
        }

        $vers = self::versOu($mission);
        if ($vers === null) {
            return response()->json(['message' => 'Aucun trajet à faire à cette étape.', 'statut' => $mission->statut, 'vers' => null], 422);
        }

        $extremites = SuiviGps::extremites($mission);
        $cible = $extremites[$vers];
        $base = [
            'sujet_type' => $type, 'sujet_id' => $mission->id, 'numero' => SuiviGps::numero($mission),
            'statut' => $mission->statut, 'vers' => $vers, 'cible' => $cible,
            'details' => SuiviGps::details($mission), 'actions' => self::actions($mission, $request),
        ];

        if ($cible['latitude'] === null || $cible['longitude'] === null) {
            return response()->json([...$base, 'itineraire' => null,
                'message' => $vers === 'origine' ? 'Le lieu exact de prise en charge n\'est pas encore connu : appelez votre contact.' : 'Le lieu exact de destination n\'est pas encore connu : appelez votre contact.']);
        }

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;
        $route = $itineraire->calculer($lat, $lng, (float) $cible['latitude'], (float) $cible['longitude']);
        $restantM = SuiviGps::distanceKm($lat, $lng, (float) $cible['latitude'], (float) $cible['longitude']) * 1000;

        return response()->json([...$base,
            'itineraire' => [...$route, 'arrivee_vers' => now()->addSeconds((int) round($route['secondes']))->toIso8601String()],
            'proche' => $restantM <= self::ARRIVEE_M,
            'distance_directe_m' => (int) round($restantM),
        ]);
    }

    /** Prochain lieu à rejoindre selon l'étape : « origine » (prise en charge), « destination », ou null. */
    public static function versOu(Model $m): ?string
    {
        if ($m instanceof Reservation) {
            if ($m->mode_transport === CircuitDepot::MODE_PRODUCTEUR) {
                return in_array($m->statut, ['confirmee', 'en_route'], true) ? 'destination' : null;
            }

            return match ($m->statut) {
                'prise_en_charge', 'au_point_collecte' => 'origine',
                'chargee', 'en_route' => 'destination',
                default => null,
            };
        }

        return match ($m->statut) {
            'prise_en_charge' => 'origine',
            'chargee', 'en_cours' => 'destination',
            default => null,
        };
    }

    private static function actions(Model $m, Request $request): array
    {
        $bloque = Incident::bloque(SuiviGps::type($m), $m->id);

        return $m instanceof Livraison
            ? CircuitLivraison::actionsLivraison($m, $request->user(), $bloque)
            : CircuitDepot::actions($m, $request->user(), $bloque);
    }
}
