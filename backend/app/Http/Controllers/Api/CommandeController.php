<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommandeRequest;
use App\Http\Requests\UpdateCommandeRequest;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Stock;
use App\Notifications\CommandeConfirmeeNotification;
use App\Notifications\NouvelleCommandeNotification;
use App\Notifications\OperationModifieeNotification;
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

        // Verrou + revérification : une modification envoyée pendant la
        // confirmation ne peut plus s'appliquer à une commande déjà confirmée
        // (stock déduit de l'ancienne quantité, commande affichant la nouvelle).
        $refus = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être modifiées.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            // Si la quantité change, re-vérifier la disponibilité (règle métier 1)
            if ($request->has('quantite')) {
                $stock = Stock::whereKey($c->stock_id)->lockForUpdate()->first();

                if ($request->quantite > $stock->quantite) {
                    return response()->json([
                        'message'             => 'Quantité insuffisante en stock.',
                        'quantite_disponible' => $stock->quantite,
                        'quantite_demandee'   => $request->quantite,
                    ], 422);
                }
            }

            $c->update($request->validated());

            return null;
        });

        if ($refus) {
            return $refus;
        }

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

        $refus = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être annulées. Une commande confirmée est déjà préparée par l\'entrepôt : contactez-le.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            $c->statut = 'annulee';
            $c->save();
            HistoriqueOperation::consigner('commande', $c->id, 'annulation', $request->user(), 'en_attente', 'annulee', $request->input('motif'));

            return null;
        });

        if ($refus) {
            return $refus;
        }

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

        // Contrôle de périmètre avant toute autre vérification et toute écriture
        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

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

        // Règle métier 2 — transaction atomique : confirmation + déduction stock.
        // Commande et stock sont verrouillés (FOR UPDATE) puis revérifiés : deux
        // confirmations simultanées ne peuvent pas déduire deux fois la quantité.
        $refus = DB::transaction(function () use ($commande, &$stock) {
            $commandeVerrouillee = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($commandeVerrouillee->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être confirmées.',
                    'statut_actuel' => $commandeVerrouillee->statut,
                ], 422);
            }

            $stock = Stock::whereKey($commandeVerrouillee->stock_id)->lockForUpdate()->first();

            if (! $stock || $commandeVerrouillee->quantite > $stock->quantite) {
                return response()->json([
                    'message'             => 'Stock insuffisant pour confirmer cette commande.',
                    'quantite_disponible' => $stock?->quantite,
                    'quantite_demandee'   => $commandeVerrouillee->quantite,
                ], 422);
            }

            $commandeVerrouillee->statut = 'confirmee';
            $commandeVerrouillee->save();

            $stock->quantite -= $commandeVerrouillee->quantite;

            if ($stock->quantite == 0) {
                $stock->statut      = 'epuise';
                $stock->date_sortie = now()->toDateString();
            }

            $stock->save();

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $commande->acheteur?->utilisateur?->notify(new CommandeConfirmeeNotification($commande));

        return response()->json([
            'message'       => 'Commande confirmée. Stock mis à jour.',
            'commande'      => $commande->fresh()->load('stock'),
            'stock_restant' => $stock->quantite,
        ]);
    }

    /**
     * Commandes portant sur les stocks de l'entrepôt du gestionnaire connecté
     * (toutes pour l'administrateur), avec la livraison et son transporteur.
     * Filtre facultatif : ?statut=en_attente|confirmee|annulee|livree.
     */
    public function indexEntrepot(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        $request->validate(['statut' => ['nullable', 'in:en_attente,confirmee,annulee,livree']]);

        $query = Commande::with([
            'acheteur:id,utilisateur_id',
            'acheteur.utilisateur:id,nom,prenom,telephone',
            'livraison:id,commande_id,numero_livraison,statut,transporteur_id,date_depart',
            'livraison.transporteur:id,utilisateur_id,type_vehicule',
            'livraison.transporteur.utilisateur:id,nom,prenom,telephone',
            'historique.utilisateur:id,nom,prenom,role',
        ])->when($request->statut, fn ($q, $statut) => $q->where('statut', $statut))
          ->orderByDesc('date_commande');

        if ($utilisateur->role !== 'administrateur') {
            $entrepot = $utilisateur->entrepot;

            if (! $entrepot) {
                return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
            }

            $query->whereHas('stock', fn ($q) => $q->where('entrepot_id', $entrepot->id));
        }

        return response()->json(['commandes' => $query->get()]);
    }

    /** Refus par l'entrepôt d'une commande en attente (motif obligatoire, acheteur notifié). */
    public function refuser(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif du refus.']);

        return $this->annulerParLEntrepot($request, $id, 'en_attente', 'refus', 'Commande refusée. L\'acheteur a été notifié.');
    }

    /**
     * Annulation par l'entrepôt d'une commande confirmée par erreur, tant
     * qu'aucune livraison n'a été créée : la quantité déduite est remise en stock.
     */
    public function annulerConfirmation(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif de l\'annulation.']);

        return $this->annulerParLEntrepot($request, $id, 'confirmee', 'annulation_confirmation', 'Commande annulée, quantité remise en stock. L\'acheteur a été notifié.');
    }

    private function annulerParLEntrepot(Request $request, int $id, string $depuis, string $action, string $messageSucces): JsonResponse
    {
        $commande = Commande::with(['stock', 'acheteur.utilisateur'])->find($id);

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $refus = DB::transaction(function () use ($commande, $depuis, $action, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== $depuis) {
                return response()->json([
                    'message'       => $depuis === 'en_attente'
                        ? 'Seules les commandes en attente peuvent être refusées.'
                        : 'Seules les commandes confirmées peuvent être annulées par l\'entrepôt.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            if ($depuis === 'confirmee') {
                if ($c->livraison()->exists()) {
                    return response()->json([
                        'message'       => 'Une livraison existe déjà pour cette commande : elle ne peut plus être annulée.',
                        'statut_actuel' => $c->statut,
                    ], 422);
                }

                // Inverse exact de confirmer() : la quantité déduite revient au stock.
                $stock = Stock::whereKey($c->stock_id)->lockForUpdate()->first();

                if ($stock) {
                    $stock->quantite += $c->quantite;

                    if ($stock->statut === 'epuise' && $stock->quantite > 0) {
                        $stock->statut      = 'disponible';
                        $stock->date_sortie = null;
                    }

                    $stock->save();
                }
            }

            $c->statut = 'annulee';
            $c->save();
            HistoriqueOperation::consigner('commande', $c->id, $action, $request->user(), $depuis, 'annulee', $request->input('motif'),
                $depuis === 'confirmee' ? ['quantite_remise_en_stock' => $c->quantite] : []);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $commande->acheteur?->utilisateur?->notify(new OperationModifieeNotification("commande_{$action}", sprintf(
            'Votre commande %s (%s) a été %s par l\'entrepôt. Motif : %s',
            $commande->numero_commande, $commande->produit, $depuis === 'en_attente' ? 'refusée' : 'annulée', $request->input('motif'),
        ), ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande]));

        return response()->json(['message' => $messageSucces, 'commande' => $commande->fresh()->load('stock')]);
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
