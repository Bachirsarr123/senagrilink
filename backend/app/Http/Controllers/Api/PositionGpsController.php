<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionGpsRequest;
use App\Models\Livraison;
use App\Models\PositionGps;
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
            ->exists();

        if (! $livraisonEnCours) {
            return response()->json([
                'message' => 'Aucune livraison en cours : la position ne peut pas être enregistrée.',
            ], 422);
        }

        $position = PositionGps::create([
            'transporteur_id' => $transporteur->id,
            'latitude'        => $request->latitude,
            'longitude'       => $request->longitude,
            'timestamp'       => now(),
        ]);

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
        ])->find($id);

        if (! $livraison) {
            return response()->json(['message' => 'Livraison introuvable.'], 404);
        }

        if (! $this->peutConsulterPosition($request, $livraison)) {
            return response()->json(['message' => 'Accès non autorisé à cette position.'], 403);
        }

        if (! $livraison->transporteur_id) {
            return response()->json(['message' => 'Aucun transporteur assigné à cette livraison.'], 404);
        }

        $position = PositionGps::where('transporteur_id', $livraison->transporteur_id)
            ->orderByDesc('timestamp')
            ->first();

        if (! $position) {
            return response()->json(['message' => 'Aucune position disponible pour cette livraison.'], 404);
        }

        return response()->json([
            'livraison_id'     => $livraison->id,
            'statut_livraison' => $livraison->statut,
            'position'         => $position,
        ]);
    }

    // ── Helper privé ─────────────────────────────────────────────────────────

    private function peutConsulterPosition(Request $request, Livraison $livraison): bool
    {
        $utilisateur = $request->user();

        if ($utilisateur->role === 'administrateur') {
            return true;
        }

        if ($utilisateur->role === 'acheteur_gros') {
            $acheteur = $utilisateur->acheteurGros;

            return $acheteur && $livraison->commande?->acheteur_id === $acheteur->id;
        }

        if ($utilisateur->role === 'producteur') {
            $producteur = $utilisateur->producteur;

            return $producteur && $livraison->commande?->stock?->production?->producteur_id === $producteur->id;
        }

        return false;
    }
}
