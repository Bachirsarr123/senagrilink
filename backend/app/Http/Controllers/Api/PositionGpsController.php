<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionGpsRequest;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Services\EstimationTrajet;
use App\Support\SuiviGps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionGpsController extends Controller
{
    // ── Transporteur ─────────────────────────────────────────────────────────

    /**
     * Enregistrer la position GPS du transporteur connecté.
     *
     * Règle métier : la position n'est acceptée que s'il existe une livraison
     * en_cours pour ce transporteur (pas de suivi GPS hors mission active).
     */
    public function store(StorePositionGpsRequest $request): JsonResponse
    {
        $transporteur = $request->user()->transporteur;

        if (! $transporteur) {
            return response()->json(['message' => 'Profil transporteur introuvable.'], 404);
        }

        $livraisonEnCours = Livraison::where('transporteur_id', $transporteur->id)
            ->where('statut', 'en_cours')
            ->orderByDesc('parti_le')
            ->first();

        if (! $livraisonEnCours) {
            return response()->json([
                'message' => 'Aucune livraison en cours : la position ne peut pas être enregistrée.',
            ], 422);
        }

        // Ancienne route (une position à la fois) : rattachée à la livraison en cours.
        $position = PositionGps::create([
            'transporteur_id' => $transporteur->id,
            'utilisateur_id'  => $request->user()->id,
            'sujet_type'      => 'livraison',
            'sujet_id'        => $livraisonEnCours->id,
            'latitude'        => $request->latitude,
            'longitude'       => $request->longitude,
            'timestamp'       => now(),
            'recue_le'        => now(),
        ]);
        app(EstimationTrajet::class)->actualiser($livraisonEnCours, $position);

        return response()->json([
            'message'  => 'Position enregistrée.',
            'position' => $position,
        ], 201);
    }

    // ── Producteur / Acheteur ────────────────────────────────────────────────

    /**
     * Dernière position connue du transporteur assigné à une livraison.
     * Accessible au producteur concerné, à l'acheteur concerné et à l'administrateur.
     */
    public function showForLivraison(Request $request, int $id): JsonResponse
    {
        $livraison = Livraison::with([
            'commande.acheteur',
            'commande.stock.production.producteur',
            'commande.stock.entrepot',
        ])->find($id);

        if (! $livraison) {
            return response()->json(['message' => 'Livraison introuvable.'], 404);
        }

        if (! $this->peutConsulterPosition($request, $livraison)) {
            return response()->json(['message' => 'Accès non autorisé à cette position.'], 403);
        }

        // Repères de la livraison, utiles même sans position (statut, lien vers la
        // traçabilité du lot) : ce sont des données que ces acteurs voient déjà.
        $infos = [
            'numero_livraison' => $livraison->numero_livraison,
            'produit'          => $livraison->commande?->produit,
            'quantite'         => $livraison->commande?->quantite,
            'date_depart'      => $livraison->date_depart,
            'date_livraison'   => $livraison->date_livraison,
            'code_tracabilite' => $livraison->commande?->stock?->production?->code_tracabilite,
        ];

        if (! $livraison->transporteur_id) {
            return response()->json([
                'message'          => 'Aucun transporteur assigné à cette livraison.',
                'statut_livraison' => $livraison->statut,
                'livraison'        => $infos,
            ], 404);
        }

        // R5 — la position est celle du transporteur, pas de la livraison : hors
        // livraison en cours (pas démarrée, terminée, en problème), on ne l'expose
        // pas, sinon une ancienne livraison permettrait de suivre ses autres missions.
        if ($livraison->statut !== 'en_cours') {
            return response()->json([
                'message'          => 'Suivi en direct disponible uniquement pendant la livraison en cours.',
                'statut_livraison' => $livraison->statut,
                'livraison'        => $infos,
            ], 404);
        }

        // Positions de CETTE livraison uniquement (jamais celles d'une autre mission du transporteur).
        $position = PositionGps::where('sujet_type', 'livraison')->where('sujet_id', $livraison->id)
            ->orderByDesc('timestamp')
            ->first();

        if (! $position) {
            return response()->json([
                'message'          => 'Aucune position disponible pour cette livraison.',
                'statut_livraison' => $livraison->statut,
                'livraison'        => $infos,
            ], 404);
        }

        $entrepot = $livraison->commande?->stock?->entrepot;

        return response()->json([
            'livraison_id'     => $livraison->id,
            'statut_livraison' => $livraison->statut,
            'origine'          => [
                'nom'       => $entrepot?->nom_entrepot,
                'latitude'  => $entrepot?->latitude,
                'longitude' => $entrepot?->longitude,
            ],
            'destination' => [
                'adresse'   => $livraison->destination,
                'latitude'  => $livraison->destination_latitude,
                'longitude' => $livraison->destination_longitude,
            ],
            'position'  => $position,
            'livraison' => $infos,
            // Heure du serveur : l'âge de la position se calcule sans dépendre de
            // l'horloge de l'appareil (une position ancienne n'est pas « en direct »).
            'genere_le' => now()->toIso8601String(),
        ]);
    }

    // ── Helper privé ─────────────────────────────────────────────────────────

    /** Acteurs de la livraison (acheteur, producteur du lot, gestionnaire de l'entrepôt, transporteur, administrateur). */
    private function peutConsulterPosition(Request $request, Livraison $livraison): bool
    {
        return SuiviGps::peutVoir($livraison, $request->user());
    }
}
