<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Producteur;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use App\Support\AFaire;
use App\Support\CircuitDepot;
use App\Support\CircuitLivraison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Ma journée » du gestionnaire (lot 11) : ce qui arrive, ce qui est à préparer,
 * ce qui part, et en tête seulement ce qui demande son attention. Le pilote
 * automatique fait avancer le reste. Fiche par agriculteur pour s'y retrouver.
 */
class JourneeController extends Controller
{
    public function journee(Request $request): JsonResponse
    {
        $u = $request->user();
        $e = $u->entrepot;
        if (! $e) {
            return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
        }

        $arrivent = Reservation::with(['producteur.utilisateur:id,nom,prenom,telephone', 'transporteur.utilisateur:id,nom,prenom,telephone', 'production:id,code_tracabilite'])
            ->where('entrepot_id', $e->id)
            ->whereIn('statut', ['confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot'])
            ->orderByRaw("case statut when 'arrivee_entrepot' then 0 when 'en_route' then 1 else 2 end")->orderBy('arrivee_estimee_le')->orderBy('id')
            ->get()->map(fn (Reservation $r) => [
                'id' => $r->id, 'numero' => $r->numero_reservation, 'produit' => $r->produit, 'quantite' => (float) $r->quantite_reservee,
                'statut' => $r->statut, 'etape' => CircuitDepot::prochaineEtape($r)['libelle'] ?? null,
                'producteur' => self::personne($r->producteur?->utilisateur), 'producteur_id' => $r->producteur_id,
                'transporteur' => $r->mode_transport === CircuitDepot::MODE_PRODUCTEUR ? null : self::personne($r->transporteur?->utilisateur),
                'livre_lui_meme' => $r->mode_transport === CircuitDepot::MODE_PRODUCTEUR,
                'arrivee_estimee_le' => $r->arrivee_estimee_le?->toIso8601String(), 'distance_restante_km' => $r->distance_restante_km,
                'a_receptionner' => $r->statut === 'arrivee_entrepot',
            ]);

        $aPreparer = Commande::with(['acheteur.utilisateur:id,nom,prenom,telephone'])
            ->whereHas('stock', fn ($q) => $q->where('entrepot_id', $e->id))
            ->whereIn('statut', ['en_attente', 'confirmee', 'en_preparation'])
            ->orderByRaw("case statut when 'en_preparation' then 0 when 'confirmee' then 1 else 2 end")->orderBy('date_commande')
            ->get()->map(fn (Commande $c) => [
                'id' => $c->id, 'numero' => $c->numero_commande, 'produit' => $c->produit, 'quantite' => (float) $c->quantite,
                'statut' => $c->statut, 'acheteur' => self::personne($c->acheteur?->utilisateur),
                'action' => $c->statut === 'en_attente' ? 'confirmer' : 'prete',
            ]);

        $partent = Livraison::with(['commande:id,numero_commande,produit,quantite,acheteur_id', 'commande.acheteur.utilisateur:id,nom,prenom,telephone', 'transporteur.utilisateur:id,nom,prenom,telephone'])
            ->whereHas('commande.stock', fn ($q) => $q->where('entrepot_id', $e->id))
            ->whereIn('statut', ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee'])
            ->orderBy('id')
            ->get()->map(fn (Livraison $l) => [
                'id' => $l->id, 'numero' => $l->numero_livraison, 'commande_id' => $l->commande_id, 'produit' => $l->commande?->produit,
                'quantite' => (float) $l->commande?->quantite, 'statut' => $l->statut,
                'etape' => CircuitLivraison::prochaineEtape($l->commande->setRelation('livraison', $l))['libelle'] ?? null,
                'acheteur' => self::personne($l->commande?->acheteur?->utilisateur), 'destination' => $l->destination,
                'transporteur' => self::personne($l->transporteur?->utilisateur),
                'sans_transporteur' => $l->transporteur_id === null, 'mission_acceptee' => $l->mission_acceptee_le !== null,
                'arrivee_estimee_le' => $l->arrivee_estimee_le?->toIso8601String(),
            ]);

        return response()->json([
            'attention' => AFaire::pour($u),
            'arrivent' => $arrivent, 'a_preparer' => $aPreparer, 'partent' => $partent,
            'pilote' => $e->only(['auto_accepter_depots', 'auto_confirmer_commandes', 'auto_affecter_transporteurs', 'delai_reponse_min']),
        ]);
    }

    /** Agriculteurs qui travaillent avec cet entrepôt, cherchables par nom, téléphone ou région. */
    public function producteurs(Request $request): JsonResponse
    {
        $e = $request->user()->entrepot;
        if (! $e) {
            return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
        }
        $q = trim((string) $request->query('q', ''));

        $ids = Reservation::where('entrepot_id', $e->id)->distinct()->pluck('producteur_id');
        $producteurs = Producteur::with('utilisateur:id,nom,prenom,telephone')->whereIn('id', $ids)
            // Recherche sans tenir compte des majuscules (PostgreSQL comme SQLite)
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->whereRaw('lower(region) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereHas('utilisateur', fn ($u) => $u->whereRaw("lower(prenom || ' ' || nom) like ?", ['%'.mb_strtolower($q).'%'])
                    ->orWhere('telephone', 'like', "%{$q}%"))))
            ->get()
            ->map(fn (Producteur $p) => [
                'id' => $p->id, ...self::personne($p->utilisateur), 'region' => $p->region,
                'depots_en_cours' => Reservation::where('entrepot_id', $e->id)->where('producteur_id', $p->id)->whereIn('statut', CircuitDepot::STATUTS_ACTIFS)->count(),
                'stock_kg' => (float) Stock::where('entrepot_id', $e->id)->whereHas('production', fn ($s) => $s->where('producteur_id', $p->id))->sum('quantite'),
                'dernier_depot' => Reservation::where('entrepot_id', $e->id)->where('producteur_id', $p->id)->max('date_reservation'),
            ])
            ->sortByDesc('dernier_depot')->values();

        return response()->json(['producteurs' => $producteurs]);
    }

    public function producteur(Request $request, int $id): JsonResponse
    {
        $e = $request->user()->entrepot;
        $p = Producteur::with('utilisateur:id,nom,prenom,telephone,adresse')->find($id);
        if (! $e || ! $p || ! Reservation::where('entrepot_id', $e->id)->where('producteur_id', $id)->exists()) {
            return response()->json(['message' => 'Agriculteur introuvable pour cet entrepôt.'], 404);
        }

        $stocks = Stock::with('production:id,code_tracabilite,type_culture,prix_unitaire,producteur_id')->where('entrepot_id', $e->id)
            ->whereHas('production', fn ($s) => $s->where('producteur_id', $id))->get();

        return response()->json([
            'producteur' => ['id' => $p->id, ...self::personne($p->utilisateur), 'adresse' => $p->utilisateur?->adresse, 'region' => $p->region],
            'depots' => Reservation::where('entrepot_id', $e->id)->where('producteur_id', $id)->orderByDesc('id')->limit(50)
                ->get(['id', 'numero_reservation', 'produit', 'quantite_reservee', 'quantite_recue', 'statut', 'date_reservation']),
            'stocks' => $stocks->map(fn (Stock $s) => [
                'id' => $s->id, 'produit' => $s->produit, 'quantite' => (float) $s->quantite, 'quantite_disponible' => (float) $s->quantite_disponible,
                'lot' => $s->production?->code_tracabilite, 'prix_unitaire' => $s->production?->prix_unitaire, 'statut' => $s->statut,
            ]),
            'ventes' => Commande::whereIn('stock_id', $stocks->pluck('id'))->orderByDesc('id')->limit(50)
                ->get(['id', 'numero_commande', 'produit', 'quantite', 'prix', 'statut', 'date_commande']),
        ]);
    }

    private static function personne(?Utilisateur $u): ?array
    {
        return $u ? ['nom' => trim($u->prenom.' '.$u->nom), 'telephone' => $u->telephone] : null;
    }
}
