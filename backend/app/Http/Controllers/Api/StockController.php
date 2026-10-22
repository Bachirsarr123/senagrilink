<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrepot;
use App\Models\Stock;
use App\Services\GestionStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function __construct(private readonly GestionStock $gestionStock)
    {
    }

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
     * Ajustement manuel motivé (perte, avarie, retrait par le producteur,
     * correction d'inventaire). Il n'existe plus d'entrée ni de sortie libre :
     * une entrée vient d'une réception, une sortie de commande du départ de
     * sa livraison ; tout le reste passe ici et laisse un mouvement.
     */
    public function ajuster(Request $request, int $id): JsonResponse
    {
        $donnees = $request->validate([
            'motif_type' => ['required', Rule::in(GestionStock::MOTIFS_AJUSTEMENT)],
            'quantite'   => ['required', 'numeric', 'min:0.01'],
            'sens'       => ['required', Rule::in(['retrait', 'ajout'])],
            'motif'      => ['required', 'string', 'max:500'],
        ], [
            'motif_type.required' => 'Choisissez la raison de l\'ajustement.',
            'motif_type.in'       => 'Raison inconnue : perte, avarie, retrait par le producteur ou correction d\'inventaire.',
            'quantite.min'        => 'La quantité doit être supérieure à zéro.',
            'motif.required'      => 'Expliquez l\'ajustement en quelques mots.',
        ]);

        if ($donnees['sens'] === 'ajout' && $donnees['motif_type'] !== 'correction_inventaire') {
            return response()->json(['message' => 'Seule une correction d\'inventaire peut augmenter le stock.'], 422);
        }

        $stock = $this->stockDeLEntrepot($request, $id);

        if ($stock instanceof JsonResponse) {
            return $stock;
        }

        $variation = $donnees['sens'] === 'retrait' ? -(float) $donnees['quantite'] : (float) $donnees['quantite'];

        $stock = DB::transaction(fn () => $this->gestionStock->ajuster(
            $stock->id, $donnees['motif_type'], $variation, $donnees['motif'], $request->user(),
        ));

        return response()->json([
            'message' => 'Ajustement enregistré.',
            'stock'   => $stock->fresh()->load('production:id,code_tracabilite,type_culture'),
        ]);
    }

    /** Modifier le seuil d'alerte d'un stock (null : aucune alerte). */
    public function seuil(Request $request, int $id): JsonResponse
    {
        $donnees = $request->validate(['seuil_alerte' => ['present', 'nullable', 'numeric', 'min:0']]);
        $stock   = $this->stockDeLEntrepot($request, $id);

        if ($stock instanceof JsonResponse) {
            return $stock;
        }

        $stock->update(['seuil_alerte' => $donnees['seuil_alerte']]);

        return response()->json(['message' => 'Seuil d\'alerte mis à jour.', 'stock' => $stock->fresh()]);
    }

    /** Journal des mouvements d'un stock (plus ancien d'abord). */
    public function mouvements(Request $request, int $id): JsonResponse
    {
        $stock = $this->stockDeLEntrepot($request, $id);

        if ($stock instanceof JsonResponse) {
            return $stock;
        }

        return response()->json([
            'stock'      => $stock->load('production:id,code_tracabilite,type_culture'),
            'mouvements' => $stock->mouvements()->with('utilisateur:id,nom,prenom,role')->get(),
        ]);
    }

    /**
     * Produits dont la quantité disponible (physique - réservé) est inférieure
     * ou égale au seuil d'alerte.
     */
    public function alertes(Request $request): JsonResponse
    {
        $entrepot = $this->getEntrepot($request);

        if ($entrepot instanceof JsonResponse) {
            return $entrepot;
        }

        $alertes = Stock::where('entrepot_id', $entrepot->id)
            ->whereNotNull('seuil_alerte')
            ->whereRaw('(quantite - quantite_reservee) <= seuil_alerte')
            ->where('statut', '!=', 'epuise')
            ->with(['production:id,code_tracabilite,type_culture'])
            ->orderByRaw('(quantite - quantite_reservee)')
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

    /** Stock de l'entrepôt du gestionnaire (tous pour l'administrateur), sinon 404. */
    private function stockDeLEntrepot(Request $request, int $id): Stock|JsonResponse
    {
        $utilisateur = $request->user();
        $stock       = Stock::find($id);

        $autorise = $stock && ($utilisateur->role === 'administrateur'
            || (int) $stock->entrepot_id === (int) $utilisateur->entrepot?->id);

        return $autorise ? $stock : response()->json(['message' => 'Stock introuvable dans votre entrepôt.'], 404);
    }

    private function construireRapportEntrepot(Entrepot $entrepot, $stocks): array
    {
        $disponibles = $stocks->where('statut', 'disponible');
        $epuises     = $stocks->where('statut', 'epuise');
        $enAlerte    = $stocks->filter(fn ($s) => $s->seuil_alerte !== null && $s->quantite_disponible <= $s->seuil_alerte);

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
            'quantite_reservee'     => $stocks->sum('quantite_reservee'),
            'quantite_disponible'   => $stocks->sum(fn ($s) => $s->quantite_disponible),
            'stocks'                => $stocks->values(),
        ];
    }
}
