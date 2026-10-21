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
            'prix_unitaire'    => $request->prix_unitaire,
            'statut'           => 'en_attente',
        ]);

        HistoriqueOperation::consigner('production', $production->id, 'creation', $request->user(), null, 'en_attente', null, [
            'quantite_estimee' => (float) $production->quantite_estimee, 'prix_unitaire' => (float) $production->prix_unitaire,
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
     * Un producteur ne peut modifier que ses propres productions. Dès que le
     * lot est entré en entrepôt, seul son prix au kilo reste modifiable (les
     * commandes déjà passées gardent le prix figé à la commande).
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

        $modifications = $request->validated();

        if ($production->estVerrouille()) {
            $interdits = array_values(array_filter(
                Production::CHAMPS_VERROUILLES,
                fn ($champ) => array_key_exists($champ, $modifications)
                    && ! $this->memeValeur($champ, $modifications[$champ], $production->getRawOriginal($champ)),
            ));

            if ($interdits !== []) {
                return response()->json([
                    'message'          => 'Ce lot est en entrepôt : ses informations ne peuvent plus être modifiées. Seul le prix au kilo peut encore changer.',
                    'champs_refuses'   => $interdits,
                ], 422);
            }

            $modifications = array_diff_key($modifications, array_flip(Production::CHAMPS_VERROUILLES));
        }

        $avant = $production->only(array_keys($modifications));
        $production->update($modifications);
        $changes = array_diff_key($production->getChanges(), ['updated_at' => true]);

        if ($changes !== []) {
            HistoriqueOperation::consigner('production', $production->id, 'modification', $utilisateur, $production->statut, $production->statut, null, [
                'avant' => array_intersect_key($avant, $changes), 'apres' => $changes,
            ]);
        }

        return response()->json([
            'message'    => 'Production mise à jour.',
            'production' => $production->fresh(),
        ]);
    }

    /** Valeur renvoyée inchangée par le client (nombres et dates comparés sans tenir compte du format). */
    private function memeValeur(string $champ, mixed $nouvelle, mixed $actuelle): bool
    {
        if ($nouvelle === null || $actuelle === null || $nouvelle === '' || $actuelle === '') {
            return ($nouvelle ?? '') === ($actuelle ?? '');
        }

        if (is_numeric($nouvelle) && is_numeric($actuelle)) {
            return abs((float) $nouvelle - (float) $actuelle) < 1e-6;
        }

        if ($champ === 'date_recolte') {
            return substr((string) $nouvelle, 0, 10) === substr((string) $actuelle, 0, 10);
        }

        return (string) $nouvelle === (string) $actuelle;
    }

    /**
     * Consulter les commandes disponibles (demandes d'acheteurs en attente).
     * Permet au producteur de planifier ses ventes.
     */
    public function commandesDisponibles(Request $request): JsonResponse
    {
        $commandes = Commande::where('statut', 'en_attente')
            ->with([
                'stock:id,produit,quantite,quantite_reservee,entrepot_id,production_id',
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
