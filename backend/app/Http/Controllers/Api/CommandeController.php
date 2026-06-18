<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandeController extends Controller
{
    // ── Acheteur en gros ─────────────────────────────────────────────────────

    public function catalogue(Request $request): JsonResponse
    {
        return response()->json(['message' => 'À implémenter — étape 7']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'À implémenter — étape 7']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'À implémenter — étape 7']);
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json(['message' => 'À implémenter — étape 7']);
    }

    public function historique(Request $request): JsonResponse
    {
        return response()->json(['message' => 'À implémenter — étape 7']);
    }

    // ── Gestionnaire d'entrepôt ───────────────────────────────────────────────

    /**
     * Confirmer une commande en_attente.
     *
     * Règle métier 1 : quantité stock >= quantité commandée, sinon 422.
     * Règle métier 2 : déduction atomique du stock lors de la confirmation.
     */
    public function confirmer(Request $request, int $id): JsonResponse
    {
        $commande = Commande::with('stock')->findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json([
                'message' => 'Seules les commandes en attente peuvent être confirmées.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        $stock = $commande->stock;

        if (! $stock) {
            return response()->json([
                'message' => 'Aucun stock associé à cette commande.',
            ], 422);
        }

        // Règle métier 1 — vérification de la disponibilité du stock
        if ($commande->quantite > $stock->quantite) {
            return response()->json([
                'message'             => 'Stock insuffisant pour confirmer cette commande.',
                'quantite_disponible' => $stock->quantite,
                'quantite_demandee'   => $commande->quantite,
            ], 422);
        }

        // Règle métier 2 — transaction atomique : confirmation + déduction stock
        DB::transaction(function () use ($commande, $stock) {
            $commande->statut = 'confirmee';
            $commande->save();

            $stock->quantite -= $commande->quantite;

            if ($stock->quantite == 0) {
                $stock->statut      = 'epuise';
                $stock->date_sortie = now()->toDateString();
            }

            $stock->save();
        });

        return response()->json([
            'message'          => 'Commande confirmée. Stock mis à jour.',
            'commande'         => $commande->fresh()->load('stock'),
            'stock_restant'    => $stock->quantite,
        ]);
    }
}
