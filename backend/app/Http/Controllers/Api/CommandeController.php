<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommandeRequest;
use App\Http\Requests\UpdateCommandeRequest;
use App\Models\Commande;
use App\Models\Stock;
use App\Notifications\CommandeConfirmeeNotification;
use App\Notifications\NouvelleCommandeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandeController extends Controller
{
    // ── Acheteur en gros ─────────────────────────────────────────────────────

    /**
     * Catalogue : tous les stocks disponibles (quantite > 0) de tous les entrepôts.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $stocks = Stock::where('statut', 'disponible')
            ->where('quantite', '>', 0)
            ->with([
                'entrepot:id,nom_entrepot,localisation',
                'production:id,code_tracabilite,type_culture,date_recolte',
            ])
            ->orderBy('produit')
            ->get()
            ->map(fn ($stock) => [
                'stock_id'     => $stock->id,
                'produit'      => $stock->produit,
                'quantite'     => $stock->quantite,
                'date_entree'  => $stock->date_entree,
                'entrepot'     => $stock->entrepot,
                'production'   => $stock->production,
            ]);

        return response()->json(['catalogue' => $stocks]);
    }

    /**
     * Passer une commande.
     *
     * Règle métier 1 : quantite demandée <= quantite disponible en stock.
     * Retourne 422 explicite si insuffisant — jamais de commande partielle silencieuse.
     */
    public function store(StoreCommandeRequest $request): JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $stock = Stock::findOrFail($request->stock_id);

        // Règle métier 1 — vérification avant création
        if ($request->quantite > $stock->quantite) {
            return response()->json([
                'message'             => 'Quantité insuffisante en stock. Commande non créée.',
                'quantite_disponible' => $stock->quantite,
                'quantite_demandee'   => $request->quantite,
            ], 422);
        }

        if ($stock->statut !== 'disponible') {
            return response()->json([
                'message' => 'Ce produit n\'est plus disponible en stock.',
                'statut'  => $stock->statut,
            ], 422);
        }

        $commande = Commande::create([
            'acheteur_id'    => $acheteur->id,
            'stock_id'       => $stock->id,
            'produit'        => $stock->produit,
            'quantite'       => $request->quantite,
            'prix'           => $request->prix,
            'date_commande'  => now(),
            'statut'         => 'en_attente',
        ]);

        // Notifie le producteur du lot (s'il existe) et le gestionnaire de l'entrepôt
        $stock->loadMissing(['production.producteur.utilisateur', 'entrepot.utilisateur']);

        $producteurUtilisateur = $stock->production?->producteur?->utilisateur;
        if ($producteurUtilisateur) {
            $producteurUtilisateur->notify(new NouvelleCommandeNotification($commande));
        }

        $gestionnaireUtilisateur = $stock->entrepot?->utilisateur;
        if ($gestionnaireUtilisateur) {
            $gestionnaireUtilisateur->notify(new NouvelleCommandeNotification($commande));
        }

        return response()->json([
            'message'  => 'Commande passée avec succès.',
            'commande' => $commande->load('stock.entrepot'),
        ], 201);
    }

    /**
     * Modifier une commande (quantite ou prix).
     * Uniquement possible si statut = en_attente.
     */
    public function update(UpdateCommandeRequest $request, int $id): JsonResponse
    {
        $commande = $this->commandeDeLAcheteur($request, $id);

        if ($commande instanceof JsonResponse) {
            return $commande;
        }

        if ($commande->statut !== 'en_attente') {
            return response()->json([
                'message'       => 'Seules les commandes en attente peuvent être modifiées.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        // Si la quantité change, re-vérifier la disponibilité (règle métier 1)
        if ($request->has('quantite') && $request->quantite !== $commande->quantite) {
            $stock = $commande->stock;

            if ($request->quantite > $stock->quantite) {
                return response()->json([
                    'message'             => 'Quantité insuffisante en stock.',
                    'quantite_disponible' => $stock->quantite,
                    'quantite_demandee'   => $request->quantite,
                ], 422);
            }
        }

        $commande->update($request->validated());

        return response()->json([
            'message'  => 'Commande mise à jour.',
            'commande' => $commande->fresh()->load('stock'),
        ]);
    }

    /**
     * Annuler une commande.
     * Uniquement possible si statut = en_attente (le stock n'a pas encore été déduit).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $commande = $this->commandeDeLAcheteur($request, $id);

        if ($commande instanceof JsonResponse) {
            return $commande;
        }

        if ($commande->statut !== 'en_attente') {
            return response()->json([
                'message'       => 'Seules les commandes en attente peuvent être annulées.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        $commande->statut = 'annulee';
        $commande->save();

        return response()->json([
            'message'  => 'Commande annulée.',
            'commande' => $commande->fresh(),
        ]);
    }

    /**
     * Historique des commandes de l'acheteur connecté.
     *
     * Règle métier 4 : si la livraison associée est en statut probleme,
     * un flag alerte_livraison = true est ajouté (alerte visuelle, pas de changement
     * automatique du statut commande).
     */
    public function historique(Request $request): JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $commandes = Commande::where('acheteur_id', $acheteur->id)
            ->with([
                'stock:id,produit,entrepot_id',
                'stock.entrepot:id,nom_entrepot,localisation',
                'livraison:id,commande_id,statut,date_depart,date_livraison,numero_livraison',
            ])
            ->orderBy('date_commande', 'desc')
            ->get()
            ->map(fn ($commande) => array_merge(
                $commande->toArray(),
                // Règle métier 4 — alerte visuelle si livraison en problème
                ['alerte_livraison' => $commande->livraison?->statut === 'probleme']
            ));

        return response()->json(['commandes' => $commandes]);
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
        $commande = Commande::with(['stock', 'acheteur.utilisateur'])->findOrFail($id);

        if ($commande->statut !== 'en_attente') {
            return response()->json([
                'message'       => 'Seules les commandes en attente peuvent être confirmées.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        $stock = $commande->stock;

        if (! $stock) {
            return response()->json([
                'message' => 'Aucun stock associé à cette commande.',
            ], 422);
        }

        // Règle métier 1 — re-vérification au moment de la confirmation
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

        $commande->acheteur?->utilisateur?->notify(new CommandeConfirmeeNotification($commande));

        return response()->json([
            'message'       => 'Commande confirmée. Stock mis à jour.',
            'commande'      => $commande->fresh()->load('stock'),
            'stock_restant' => $stock->quantite,
        ]);
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    /**
     * Récupère une commande appartenant à l'acheteur connecté.
     * Retourne une JsonResponse 403/404 si la commande est inaccessible.
     */
    private function commandeDeLAcheteur(Request $request, int $id): Commande|JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $commande = Commande::with('stock')->find($id);

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        if ($commande->acheteur_id !== $acheteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        return $commande;
    }
}
