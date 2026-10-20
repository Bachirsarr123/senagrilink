<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductionRequest;
use App\Http\Requests\UpdateProductionRequest;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Production;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'statut'           => 'en_attente',
        ]);

        return response()->json([
            'message'    => 'Récolte enregistrée avec succès.',
            'production' => $production,
        ], 201);
    }

    /**
     * Annuler une récolte enregistrée par erreur. Refusé dès qu'elle est
     * utilisée par une réservation active, un stock ou une commande : elle
     * n'est jamais supprimée, son statut passe à « annulee » (historisé).
     */
    public function annuler(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $production = Production::find($id);
        $producteur = $request->user()->producteur;

        if (! $production) {
            return response()->json(['message' => 'Récolte introuvable.'], 404);
        }

        if (! $producteur || $production->producteur_id !== $producteur->id) {
            return response()->json(['message' => 'Action non autorisée sur cette production.'], 403);
        }

        return DB::transaction(function () use ($production, $request) {
            $p = Production::whereKey($production->id)->lockForUpdate()->first();

            if ($p->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seule une récolte en attente (pas encore en entrepôt) peut être annulée.',
                    'statut_actuel' => $p->statut,
                ], 422);
            }

            $utilisee = $p->stocks()->exists()
                || $p->reservations()->whereIn('statut', ['en_attente', 'confirmee', 'arrivee_entrepot', 'enregistree'])->exists();

            if ($utilisee) {
                return response()->json([
                    'message'       => 'Cette récolte est liée à une réservation d\'entrepôt en cours : annulez d\'abord la réservation.',
                    'statut_actuel' => $p->statut,
                ], 422);
            }

            $p->statut = 'annulee';
            $p->save();
            HistoriqueOperation::consigner('production', $p->id, 'annulation', $request->user(), 'en_attente', 'annulee', $request->input('motif'));

            return response()->json(['message' => 'Récolte annulée.', 'production' => $p->fresh()]);
        });
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

        if ($production->statut === 'annulee') {
            return response()->json(['message' => 'Cette récolte a été annulée : elle ne peut plus être modifiée.'], 422);
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
                'stock:id,produit,quantite,entrepot_id,production_id',
                'stock.production:id,producteur_id',
                'stock.entrepot:id,nom_entrepot,localisation',
                'acheteur:id,utilisateur_id',
                'acheteur.utilisateur:id,nom,prenom',
            ])
            ->orderBy('date_commande', 'desc')
            ->get();

        // R6 — l'identité de l'acheteur n'est visible que du producteur dont le
        // lot est commandé (et de l'administrateur) ; les autres voient la demande
        // (produit, quantité, entrepôt) sans savoir qui l'a passée.
        $utilisateur  = $request->user();
        $producteurId = $utilisateur->producteur?->id;

        $commandes->each(function (Commande $commande) use ($utilisateur, $producteurId) {
            $concerne = $utilisateur->role === 'administrateur'
                || ($producteurId !== null && (int) $commande->stock?->production?->producteur_id === (int) $producteurId);

            if (! $concerne) {
                $commande->setRelation('acheteur', null);
                $commande->makeHidden('acheteur_id');
            }
            $commande->setAttribute('acheteur_masque', ! $concerne);
            $commande->stock?->unsetRelation('production');
        });

        return response()->json(['commandes_disponibles' => $commandes]);
    }
}
