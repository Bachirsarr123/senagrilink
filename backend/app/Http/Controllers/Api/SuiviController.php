<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Reservation;
use App\Services\EstimationTrajet;
use App\Support\SuiviGps;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Suivi GPS des deux circuits (lot 4) : la personne qui conduit envoie ses
 * positions (une par une, ou par paquets après une coupure réseau) ; les
 * acteurs de la mission consultent la carte, le trajet et l'heure d'arrivée.
 */
class SuiviController extends Controller
{
    /** Au-delà, une position n'est plus « en direct » (affichée comme dernière position connue). */
    public const POSITION_RECENTE_S = 60;
    public const MAX_POINTS_TRACE   = 300;

    public function __construct(private readonly EstimationTrajet $estimation)
    {
    }

    /**
     * Enregistre des positions de la mission. Acceptées pendant le trajet, et
     * après l'arrivée pour les positions prises pendant le trajet hors connexion.
     * suivi_actif = false indique au téléphone d'arrêter le partage.
     */
    public function enregistrer(Request $request, string $type, int $id): JsonResponse
    {
        $donnees = $request->validate([
            'positions'               => ['required', 'array', 'min:1', 'max:500'],
            'positions.*.latitude'    => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude'   => ['required', 'numeric', 'between:-180,180'],
            'positions.*.horodatage'  => ['nullable', 'date'],
            'positions.*.precision'   => ['nullable', 'numeric', 'min:0'],
            'positions.*.vitesse'     => ['nullable', 'numeric', 'min:0'],
            'positions.*.cap'         => ['nullable', 'numeric', 'between:0,360'],
        ]);

        $mission = $this->mission($type, $id);

        if ($mission instanceof JsonResponse) {
            return $mission;
        }

        if (! SuiviGps::estConducteur($mission, $request->user())) {
            return response()->json(['message' => 'Seule la personne qui conduit cette mission partage sa position.', 'suivi_actif' => false], 403);
        }

        [$debut, $fin] = $this->fenetre($mission);

        if ($debut === null) {
            return response()->json(['message' => 'Le partage de position commence au départ.', 'suivi_actif' => false, 'statut' => $mission->statut], 422);
        }

        $enregistrees = 0;
        $derniere     = null;

        foreach ($donnees['positions'] as $p) {
            // Heure de prise sur le téléphone ; une heure future (horloge déréglée) est ramenée à maintenant.
            $heure = isset($p['horodatage']) ? Carbon::parse($p['horodatage'])->setTimezone(config('app.timezone')) : now();
            $heure = $heure->greaterThan(now()) ? now() : $heure;

            if ($heure->lessThan($debut->copy()->subMinute()) || ($fin && $heure->greaterThan($fin->copy()->addMinute()))) {
                continue;
            }

            $existe = PositionGps::where('sujet_type', $type)->where('sujet_id', $id)->where('timestamp', $heure)->exists();

            if ($existe) {
                continue;
            }

            $position = PositionGps::create([
                'sujet_type'      => $type,
                'sujet_id'        => $id,
                'utilisateur_id'  => $request->user()->id,
                'transporteur_id' => $request->user()->transporteur?->id,
                'latitude'        => $p['latitude'],
                'longitude'       => $p['longitude'],
                'timestamp'       => $heure,
                'precision_m'     => $p['precision'] ?? null,
                'vitesse_kmh'     => $p['vitesse'] ?? null,
                'cap'             => $p['cap'] ?? null,
                'recue_le'        => now(),
            ]);
            $enregistrees++;

            if (! $derniere || $position->timestamp->greaterThan($derniere->timestamp)) {
                $derniere = $position;
            }
        }

        if ($derniere && SuiviGps::enRoute($mission)) {
            $this->estimation->actualiser($mission, $derniere);
        }

        $this->purgerParfois();

        return response()->json([
            'message'      => $enregistrees ? 'Position enregistrée.' : 'Aucune nouvelle position.',
            'enregistrees' => $enregistrees,
            'ignorees'     => count($donnees['positions']) - $enregistrees,
            'suivi_actif'  => SuiviGps::enRoute($mission),
        ], $enregistrees ? 201 : 200);
    }

    /** Carte de la mission pour ses acteurs : position, trace, trajet, arrivée estimée. */
    public function consulter(Request $request, string $type, int $id): JsonResponse
    {
        $mission = $this->mission($type, $id);

        if ($mission instanceof JsonResponse) {
            return $mission;
        }

        if (! SuiviGps::peutVoir($mission, $request->user())) {
            return response()->json(['message' => 'Accès non autorisé à ce suivi.'], 403);
        }

        $enRoute = SuiviGps::enRoute($mission);
        $base    = [
            'sujet_type'  => $type,
            'sujet_id'    => $mission->id,
            'numero'      => SuiviGps::numero($mission),
            'statut'      => $mission->statut,
            'en_route'    => $enRoute,
            'conducteur'  => SuiviGps::conducteur($mission),
            ...SuiviGps::extremites($mission),
            'genere_le'   => now()->toIso8601String(),
        ];

        // Q11 : la position n'est visible que pendant le trajet.
        if (! $enRoute) {
            return response()->json([...$base, 'position' => null, 'trace' => [], 'trajet' => null,
                'message' => 'Suivi en direct disponible uniquement pendant le trajet.']);
        }

        $trace = PositionGps::where('sujet_type', $type)->where('sujet_id', $id)
            ->orderByDesc('timestamp')->limit(self::MAX_POINTS_TRACE)->get(['latitude', 'longitude', 'timestamp', 'vitesse_kmh'])
            ->reverse()->values();
        $derniere = $trace->last();
        $age      = $derniere ? (int) $derniere->timestamp->diffInSeconds(now(), true) : null;

        return response()->json([
            ...$base,
            'parti_le'             => $mission->parti_le?->toIso8601String(),
            'position'             => $derniere ? [
                'latitude'  => $derniere->latitude,
                'longitude' => $derniere->longitude,
                'timestamp' => $derniere->timestamp->toIso8601String(),
                'vitesse'   => $derniere->vitesse_kmh,
            ] : null,
            'age_position_s'       => $age,
            'position_recente'     => $age !== null && $age <= self::POSITION_RECENTE_S,
            'trace'                => $trace->map(fn ($p) => [$p->latitude, $p->longitude])->all(),
            'trajet'               => $mission->trajet,
            'arrivee_estimee_le'   => $mission->arrivee_estimee_le?->toIso8601String(),
            'distance_restante_km' => $mission->distance_restante_km,
            'estimation_routiere'  => $mission->trajet !== null,
        ]);
    }

    /** Missions en route de la personne connectée : l'application reprend le partage au démarrage. */
    public function actifs(Request $request): JsonResponse
    {
        $u      = $request->user();
        $suivis = collect();

        if ($u->transporteur) {
            Livraison::where('transporteur_id', $u->transporteur->id)->where('statut', 'en_cours')->get()
                ->each(fn ($l) => $suivis->push(['sujet_type' => 'livraison', 'sujet_id' => $l->id, 'numero' => $l->numero_livraison]));
            Reservation::where('transporteur_id', $u->transporteur->id)->where('statut', 'en_route')->where('mode_transport', 'transporteur')->get()
                ->each(fn ($r) => $suivis->push(['sujet_type' => 'reservation', 'sujet_id' => $r->id, 'numero' => $r->numero_reservation]));
        }

        if ($u->producteur) {
            Reservation::where('producteur_id', $u->producteur->id)->where('statut', 'en_route')->where('mode_transport', 'producteur')->get()
                ->each(fn ($r) => $suivis->push(['sujet_type' => 'reservation', 'sujet_id' => $r->id, 'numero' => $r->numero_reservation]));
        }

        return response()->json(['suivis' => $suivis->values()]);
    }

    /** Fond de carte et routage configurés (aucune clé secrète : l'adresse des tuiles est publique par nature). */
    public function configuration(): JsonResponse
    {
        return response()->json([
            'tuiles_url'       => config('services.carte.tuiles_url'),
            'attribution'      => config('services.carte.attribution'),
            'routage_routier'  => (bool) config('services.routage.url'),
            'position_recente_s' => self::POSITION_RECENTE_S,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mission(string $type, int $id): Model|JsonResponse
    {
        if (! in_array($type, SuiviGps::TYPES, true)) {
            return response()->json(['message' => 'Type de suivi inconnu.'], 404);
        }

        return SuiviGps::trouver($type, $id) ?? response()->json(['message' => 'Mission introuvable.'], 404);
    }

    /** [départ, arrivée] du trajet ; départ null si la mission n'est pas encore partie. */
    private function fenetre(Model $m): array
    {
        $type = SuiviGps::type($m);

        if ($m->statut === SuiviGps::EN_ROUTE[$type]) {
            return [$m->parti_le ?? $m->mission_acceptee_le ?? now()->subHour(), null];
        }

        if (in_array($m->statut, SuiviGps::APRES_ARRIVEE[$type], true) && $m->parti_le) {
            return [$m->parti_le, $m->arrive_le ?? now()];
        }

        return [null, null];
    }

    /** Purge des positions anciennes, sans tâche planifiée : environ une requête sur 200. */
    private function purgerParfois(): void
    {
        if (random_int(1, 200) === 1) {
            PositionGps::purger();
        }
    }
}
