<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Paiement;
use App\Models\Stock;
use App\Models\Versement;
use App\Services\Paiements;
use App\Support\Lieux;
use App\Support\Tarifs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paiements (lot 12) : devis avant de commander, paiement (Wave, Orange Money
 * ou espèces à la livraison), page de paiement simulée, « Mon argent » de chaque
 * acteur, décisions de l'administrateur sur les litiges.
 */
class PaiementController extends Controller
{
    public function __construct(private readonly Paiements $paiements)
    {
    }

    /** Ce que l'acheteur paiera, avant de commander : marchandise et livraison. */
    public function devis(Request $request): JsonResponse
    {
        $d = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:stocks,id'],
            'quantite' => ['required', 'numeric', 'min:0.01'],
            ...Lieux::regles('livraison'),
        ]);
        $stock = Stock::with('production:id,prix_unitaire', 'entrepot')->find($d['stock_id']);
        [$lat, $lng] = Lieux::retenirSansMemoriser($request, 'livraison', $request->user()->acheteurGros);
        $prix = round((float) $stock->production?->prix_unitaire * (float) $d['quantite'], 2);

        return response()->json(['devis' => Tarifs::calculer($prix, (float) $d['quantite'], $stock->entrepot, $lat, $lng)]);
    }

    public function payer(Request $request): JsonResponse
    {
        $d = $request->validate([
            'commandes'   => ['required', 'array', 'min:1', 'max:20'],
            'commandes.*' => ['integer', 'distinct'],
            'moyen'       => ['required', Rule::in([...Paiements::MOYENS_EN_LIGNE, 'especes'])],
            'telephone'   => ['nullable', 'string', 'max:20'],
        ], ['moyen.in' => 'Moyen de paiement inconnu : Wave, Orange Money ou espèces à la livraison.']);

        $acheteur = $request->user()->acheteurGros;
        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $p = $this->paiements->initier($acheteur, $d['commandes'], $d['moyen'], $d['telephone'] ?? null);

        return response()->json([
            'message'  => $d['moyen'] === 'especes' ? 'Vous paierez en espèces au transporteur, à la livraison.' : 'Paiement à confirmer.',
            'paiement' => $this->presenter($p),
        ], 201);
    }

    public function voir(Request $request, string $reference): JsonResponse
    {
        return response()->json(['paiement' => $this->presenter($this->paiementDeLAcheteur($request, $reference))]);
    }

    /**
     * Simulation (en attendant Wave Business) : l'acheteur confirme comme il le
     * ferait sur son téléphone. Le vrai fournisseur appellera la même suite.
     */
    public function simuler(Request $request, string $reference): JsonResponse
    {
        $d = $request->validate(['resultat' => ['required', Rule::in(['reussi', 'echoue'])]]);
        $p = $this->paiementDeLAcheteur($request, $reference);
        if ($p->fournisseur !== 'simulation') {
            return response()->json(['message' => 'Ce paiement passe par le vrai service de paiement.'], 422);
        }
        if ($p->statut !== 'initie') {
            return response()->json(['message' => 'Ce paiement est déjà traité.', 'paiement' => $this->presenter($p)], 422);
        }

        $d['resultat'] === 'reussi' ? $this->paiements->reussi($p, 'SIM-'.strtoupper(substr(md5($p->reference), 0, 10))) : $this->paiements->echoue($p);
        $p->refresh();

        return response()->json([
            'message'  => $p->statut === 'reussi' ? sprintf('Paiement de %s FCFA réussi : commande validée, l\'entrepôt la prépare.', Paiements::fcfa($p->montant))
                : 'Paiement non abouti. Vous pouvez réessayer.',
            'paiement' => $this->presenter($p),
        ]);
    }

    /**
     * « Mon argent » : pour le producteur, l'entrepôt et le transporteur, ce qui
     * est mis de côté, versé ou à régler en main propre ; pour l'acheteur, ses paiements.
     */
    public function argent(Request $request): JsonResponse
    {
        $u = $request->user();
        if ($u->acheteurGros) {
            return response()->json(['paiements' => Paiements::deLAcheteur($u->acheteurGros)]);
        }

        $q = $u->role === 'administrateur' ? Versement::query() : Versement::where('utilisateur_id', $u->id);
        // Le transporteur n'est connu qu'à la livraison : sa part bloquée s'affiche dès qu'il a la mission
        if ($u->transporteur) {
            $q = Versement::where(fn ($w) => $w->where('utilisateur_id', $u->id)->orWhere(fn ($b) => $b->whereNull('utilisateur_id')->where('beneficiaire', 'transporteur')
                ->whereHas('commande.livraison', fn ($l) => $l->where('transporteur_id', $u->transporteur->id))));
        }
        $versements = $q->with('commande:id,numero_commande,produit,quantite,prix,frais_stockage,commission,frais_livraison,montant_producteur,statut,statut_paiement')
            ->orderByDesc('id')->limit(200)->get();

        $somme = fn (array $statuts) => round($versements->whereIn('statut', $statuts)->sum('montant'), 2);

        return response()->json([
            'totaux' => ['mis_de_cote' => $somme(['bloque']), 'verse' => $somme(['verse']), 'a_regler' => $somme(['a_regler']), 'regle' => $somme(['regle'])],
            'versements' => $versements,
            'moyen_paiement' => $u->moyen_paiement, 'numero_paiement' => $u->numero_paiement,
        ]);
    }

    /** Administrateur : paiements, litiges en cours. */
    public function admin(): JsonResponse
    {
        return response()->json([
            'paiements' => Paiement::with('commandes:id,paiement_id,numero_commande,total_a_payer,statut_paiement', 'acheteur.utilisateur:id,nom,prenom')->orderByDesc('id')->limit(200)->get(),
            'litiges' => Commande::where('statut_paiement', 'litige')->get(['id', 'numero_commande', 'produit', 'total_a_payer', 'statut']),
        ]);
    }

    /** Administrateur : litige tranché, l'argent part au producteur ou revient à l'acheteur. */
    public function decider(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['liberer', 'rembourser'])]]);
        $c = Commande::find($id);
        if (! $c || ! in_array($c->statut_paiement, ['paye', 'litige'], true)) {
            return response()->json(['message' => 'Aucun argent mis de côté pour cette commande.'], 422);
        }
        $d['decision'] === 'liberer' ? $this->paiements->liberer($c, forcer: true) : $this->paiements->rembourser($c);

        return response()->json(['message' => $d['decision'] === 'liberer' ? 'Argent versé à chacun.' : 'Acheteur remboursé.', 'commande' => $c->fresh()]);
    }

    private function paiementDeLAcheteur(Request $request, string $reference): Paiement
    {
        $p = Paiement::where('reference', $reference)->first();
        abort_if(! $p || (int) $p->acheteur_id !== (int) $request->user()->acheteurGros?->id, 404, 'Paiement introuvable.');

        return $p;
    }

    private function presenter(Paiement $p): array
    {
        $p->loadMissing('commandes:id,paiement_id,numero_commande,produit,quantite,prix,frais_livraison,total_a_payer,statut_paiement');

        return [...$p->only(['reference', 'montant', 'moyen', 'fournisseur', 'statut', 'telephone', 'paye_le']), 'commandes' => $p->commandes];
    }
}
