<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEntreeStockRequest;
use App\Http\Requests\StoreSortieStockRequest;
use App\Models\Entrepot;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    /**
     * Liste des stocks de l'entrepôt du gestionnaire connecté.
     * L'administrateur voit tous les stocks de tous les entrepôts.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->role === 'administrateur') {
            $stocks = Stock::with(['entrepot:id,nom_entrepot,localisation', 'production:id,code_tracabilite,type_culture'])
                ->orderBy('date_entree', 'desc')
                ->get();
        } else {
            $entrepot = $utilisateur->entrepot;

            if (! $entrepot) {
                return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
            }

            $stocks = Stock::where('entrepot_id', $entrepot->id)
                ->with(['production:id,code_tracabilite,type_culture'])
                ->orderBy('date_entree', 'desc')
                ->get();
        }

        return response()->json(['stocks' => $stocks]);
    }

    /**
     * Enregistrer une entrée de stock (nouvelle ligne dans l'entrepôt).
     */
    public function entree(StoreEntreeStockRequest $request): JsonResponse
    {
        $entrepot = $this->getEntrepot($request);

        if ($entrepot instanceof JsonResponse) {
            return $entrepot;
        }

        $stock = Stock::create([
            'entrepot_id'   => $entrepot->id,
            'production_id' => $request->production_id,
            'produit'       => $request->produit,
            'quantite'      => $request->quantite,
            'date_entree'   => $request->date_entree ?? now()->toDateString(),
            'seuil_alerte'  => $request->seuil_alerte,
            'statut'        => 'disponible',
        ]);

        return response()->json([
            'message' => 'Entrée de stock enregistrée.',
            'stock'   => $stock->load('production:id,code_tracabilite,type_culture'),
        ], 201);
    }

    /**
     * Enregistrer une sortie de stock (réduction de quantité).
     * Retourne 422 si la quantité disponible est insuffisante.
     */
    public function sortie(StoreSortieStockRequest $request): JsonResponse
    {
        $entrepot = $this->getEntrepot($request);

        if ($entrepot instanceof JsonResponse) {
            return $entrepot;
        }

        $stock = Stock::where('id', $request->stock_id)
            ->where('entrepot_id', $entrepot->id)
            ->first();

        if (! $stock) {
            return response()->json(['message' => 'Stock introuvable dans votre entrepôt.'], 404);
        }

        if ($request->quantite_sortie > $stock->quantite) {
            return response()->json([
                'message'             => 'Quantité insuffisante en stock.',
                'quantite_disponible' => $stock->quantite,
                'quantite_demandee'   => $request->quantite_sortie,
            ], 422);
        }

        DB::transaction(function () use ($stock, $request) {
            $stock->quantite -= $request->quantite_sortie;

            if ($stock->quantite == 0) {
                $stock->statut      = 'epuise';
                $stock->date_sortie = $request->date_sortie ?? now()->toDateString();
            }

            $stock->save();
        });

        return response()->json([
            'message'             => 'Sortie de stock enregistrée.',
            'stock'               => $stock->fresh(),
            'quantite_restante'   => $stock->quantite,
        ]);
    }

    /**
     * Produits dont la quantité est inférieure ou égale au seuil d'alerte.
     */
    public function alertes(Request $request): JsonResponse
    {
        $entrepot = $this->getEntrepot($request);

        if ($entrepot instanceof JsonResponse) {
            return $entrepot;
        }

        $alertes = Stock::where('entrepot_id', $entrepot->id)
            ->whereNotNull('seuil_alerte')
            ->whereColumn('quantite', '<=', 'seuil_alerte')
            ->where('statut', '!=', 'epuise')
            ->with(['production:id,code_tracabilite,type_culture'])
            ->orderBy('quantite')
            ->get();

        return response()->json([
            'alertes' => $alertes,
            'total'   => $alertes->count(),
        ]);
    }

    /**
     * Rapport d'inventaire : vue synthétique de l'entrepôt.
     */
    public function rapport(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->role === 'administrateur') {
            $entrepots = Entrepot::with(['stocks' => function ($q) {
                $q->orderBy('produit');
            }])->get();

            $rapport = $entrepots->map(function (Entrepot $entrepot) {
                return $this->construireRapportEntrepot($entrepot, $entrepot->stocks);
            });

            return response()->json(['rapport' => $rapport]);
        }

        $entrepot = $utilisateur->entrepot;

        if (! $entrepot) {
            return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
        }

        $stocks = Stock::where('entrepot_id', $entrepot->id)
            ->with(['production:id,code_tracabilite'])
            ->orderBy('produit')
            ->get();

        return response()->json([
            'rapport' => $this->construireRapportEntrepot($entrepot, $stocks),
        ]);
    }

    // -------------------------------------------------------------------------

    private function getEntrepot(Request $request): Entrepot|JsonResponse
    {
        $utilisateur = $request->user();

        // L'admin n'a pas de profil entrepôt — opérations de saisie réservées au gestionnaire
        $entrepot = $utilisateur->entrepot;

        if (! $entrepot) {
            return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
        }

        return $entrepot;
    }

    private function construireRapportEntrepot(Entrepot $entrepot, $stocks): array
    {
        $disponibles = $stocks->where('statut', 'disponible');
        $epuises     = $stocks->where('statut', 'epuise');
        $enAlerte    = $stocks->filter(fn ($s) => $s->seuil_alerte !== null && $s->quantite <= $s->seuil_alerte);

        return [
            'entrepot'              => [
                'id'          => $entrepot->id,
                'nom'         => $entrepot->nom_entrepot,
                'localisation'=> $entrepot->localisation,
                'capacite'    => $entrepot->capacite,
            ],
            'total_lignes_stock'    => $stocks->count(),
            'lignes_disponibles'    => $disponibles->count(),
            'lignes_epuisees'       => $epuises->count(),
            'lignes_en_alerte'      => $enAlerte->count(),
            'quantite_totale'       => $stocks->sum('quantite'),
            'stocks'                => $stocks->values(),
        ];
    }
}
