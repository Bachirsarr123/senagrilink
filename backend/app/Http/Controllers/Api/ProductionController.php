<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductionRequest;
use App\Http\Requests\UpdateProductionRequest;
use App\Models\Commande;
use App\Models\Production;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    /**
     * Liste des productions.
     * - Producteur : uniquement les siennes.
     * - Administrateur : toutes les productions.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->role === 'administrateur') {
            $productions = Production::with('producteur.utilisateur')->latest()->get();
        } else {
            $producteur = $utilisateur->producteur;

            if (! $producteur) {
                return response()->json(['message' => 'Profil producteur introuvable.'], 404);
            }

            $productions = Production::where('producteur_id', $producteur->id)
                ->with('stocks')
                ->latest()
                ->get();
        }

        return response()->json(['productions' => $productions]);
    }

    /**
     * Enregistrer une nouvelle récolte.
     * Le code_tracabilite est généré automatiquement dans le modèle.
     */
    public function store(StoreProductionRequest $request): JsonResponse
    {
        $producteur = $request->user()->producteur;

        if (! $producteur) {
            return response()->json(['message' => 'Profil producteur introuvable.'], 404);
        }

        $production = Production::create([
            'producteur_id'    => $producteur->id,
            'type_culture'     => $request->type_culture,
            'superficie'       => $request->superficie,
            'date_recolte'     => $request->date_recolte,
            'quantite_estimee' => $request->quantite_estimee,
            'quantite_reelle'  => $request->quantite_reelle,
            'statut'           => $request->statut ?? 'en_attente',
        ]);

        return response()->json([
            'message'    => 'Récolte enregistrée avec succès.',
            'production' => $production,
        ], 201);
    }

    /**
     * Modifier une production.
     * Un producteur ne peut modifier que ses propres productions.
     */
    public function update(UpdateProductionRequest $request, int $id): JsonResponse
    {
        $production = Production::findOrFail($id);

        $utilisateur = $request->user();

        if ($utilisateur->role !== 'administrateur') {
            $producteur = $utilisateur->producteur;

            if (! $producteur || $production->producteur_id !== $producteur->id) {
                return response()->json(['message' => 'Action non autorisée sur cette production.'], 403);
            }
        }

        $production->update($request->validated());

        return response()->json([
            'message'    => 'Production mise à jour.',
            'production' => $production->fresh(),
        ]);
    }

    /**
     * Consulter les commandes disponibles (demandes d'acheteurs en attente).
     * Permet au producteur de planifier ses ventes.
     */
    public function commandesDisponibles(Request $request): JsonResponse
    {
        $commandes = Commande::where('statut', 'en_attente')
            ->with([
                'stock:id,produit,quantite,entrepot_id',
                'stock.entrepot:id,nom_entrepot,localisation',
                'acheteur:id,utilisateur_id',
                'acheteur.utilisateur:id,nom,prenom',
            ])
            ->orderBy('date_commande', 'desc')
            ->get();

        return response()->json(['commandes_disponibles' => $commandes]);
    }
}
