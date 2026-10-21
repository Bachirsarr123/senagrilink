<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminStoreUtilisateurRequest;
use App\Models\AcheteurGros;
use App\Models\Commande;
use App\Models\Entrepot;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\Producteur;
use App\Models\Production;
use App\Models\Stock;
use App\Models\Transporteur;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Liste de tous les utilisateurs avec leur profil métier.
     * Filtres optionnels : ?role=xxx&statut=actif|bloque
     */
    public function indexUtilisateurs(Request $request): JsonResponse
    {
        $query = Utilisateur::with(['producteur', 'entrepot', 'acheteurGros', 'transporteur']);

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        $utilisateurs = $query->orderBy('nom')->get();

        return response()->json([
            'utilisateurs' => $utilisateurs,
            'total'        => $utilisateurs->count(),
        ]);
    }

    /**
     * Créer un utilisateur (tous les rôles, y compris administrateur).
     */
    public function storeUtilisateur(AdminStoreUtilisateurRequest $request): JsonResponse
    {
        $utilisateur = Utilisateur::create([
            'nom'          => $request->nom,
            'prenom'       => $request->prenom,
            'email'        => $request->email,
            'telephone'    => $request->telephone,
            'adresse'      => $request->adresse,
            'mot_de_passe' => $request->mot_de_passe,
            'photo'        => $request->photo,
            'role'         => $request->role,
        ]);

        $this->creerProfil($utilisateur, $request);
        HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'creation_par_admin', $request->user(), null, 'actif', null, ['role' => $utilisateur->role]);

        return response()->json([
            'message'     => 'Utilisateur créé avec succès.',
            'utilisateur' => $utilisateur->load(['producteur', 'entrepot', 'acheteurGros', 'transporteur']),
        ], 201);
    }

    /**
     * Bloquer un utilisateur (statut = bloque).
     * Un administrateur ne peut pas se bloquer lui-même.
     */
    public function bloquer(Request $request, int $id): JsonResponse
    {
        if ($request->user()->id === $id) {
            return response()->json(['message' => 'Vous ne pouvez pas bloquer votre propre compte.'], 422);
        }

        $utilisateur = Utilisateur::findOrFail($id);

        if ($utilisateur->statut === 'bloque') {
            return response()->json(['message' => 'Ce compte est déjà bloqué.'], 422);
        }

        // Bloquer le compte et révoquer tous ses jetons Sanctum (web, mobile...) :
        // sans cela, les sessions déjà ouvertes restaient utilisables.
        $refus = DB::transaction(function () use ($utilisateur, $request) {
            // R8 — les administrateurs actifs sont verrouillés (ordre stable, sans
            // interblocage) puis recomptés : deux blocages croisés simultanés ne
            // peuvent pas laisser la plateforme sans administrateur actif.
            $adminsActifs = Utilisateur::where('role', 'administrateur')
                ->where('statut', 'actif')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $cible = Utilisateur::whereKey($utilisateur->id)->lockForUpdate()->first();

            if ($cible->statut === 'bloque') {
                return response()->json(['message' => 'Ce compte est déjà bloqué.'], 422);
            }

            if ($cible->role === 'administrateur'
                && $adminsActifs->reject(fn ($adminId) => (int) $adminId === (int) $cible->id)->isEmpty()) {
                return response()->json(['message' => 'Impossible de bloquer le dernier administrateur actif.'], 422);
            }

            $cible->statut = 'bloque';
            $cible->save();
            $cible->tokens()->delete();
            $utilisateur->statut = 'bloque';
            HistoriqueOperation::consigner('utilisateur', $cible->id, 'blocage', $request->user(), 'actif', 'bloque', $request->input('motif'));

            return null;
        });

        if ($refus) {
            return $refus;
        }

        return response()->json([
            'message'     => 'Compte bloqué.',
            'utilisateur' => $utilisateur->only(['id', 'nom', 'prenom', 'email', 'role', 'statut']),
        ]);
    }

    /**
     * Débloquer un utilisateur (statut = actif).
     */
    public function debloquer(Request $request, int $id): JsonResponse
    {
        $utilisateur = Utilisateur::findOrFail($id);

        if ($utilisateur->statut === 'actif') {
            return response()->json(['message' => 'Ce compte est déjà actif.'], 422);
        }

        $utilisateur->statut = 'actif';
        $utilisateur->save();
        HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'deblocage', $request->user(), 'bloque', 'actif', $request->input('motif'));

        return response()->json([
            'message'     => 'Compte débloqué.',
            'utilisateur' => $utilisateur->only(['id', 'nom', 'prenom', 'email', 'role', 'statut']),
        ]);
    }

    /**
     * Tableau de bord global : statistiques agrégées de toute la plateforme.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json([
            'productions' => [
                'total'      => Production::count(),
                'par_statut' => Production::selectRaw('statut, count(*) as total')
                    ->groupBy('statut')
                    ->pluck('total', 'statut'),
            ],

            'stocks' => [
                'total_lignes'   => Stock::count(),
                'quantite_totale'=> (float) Stock::sum('quantite'),
                'par_statut'     => Stock::selectRaw('statut, count(*) as total')
                    ->groupBy('statut')
                    ->pluck('total', 'statut'),
                'en_alerte'      => Stock::whereNotNull('seuil_alerte')
                    ->whereColumn('quantite', '<=', 'seuil_alerte')
                    ->count(),
            ],

            'commandes' => [
                'total'      => Commande::count(),
                'par_statut' => Commande::selectRaw('statut, count(*) as total')
                    ->groupBy('statut')
                    ->pluck('total', 'statut'),
            ],

            'livraisons' => [
                'total'      => Livraison::count(),
                'par_statut' => Livraison::selectRaw('statut, count(*) as total')
                    ->groupBy('statut')
                    ->pluck('total', 'statut'),
            ],

            'utilisateurs' => [
                'total'    => Utilisateur::count(),
                'actifs'   => Utilisateur::where('statut', 'actif')->count(),
                'bloques'  => Utilisateur::where('statut', 'bloque')->count(),
                'par_role' => Utilisateur::selectRaw('role, count(*) as total')
                    ->groupBy('role')
                    ->pluck('total', 'role'),
            ],
        ]);
    }

    // ── Helper privé ──────────────────────────────────────────────────────────

    private function creerProfil(Utilisateur $utilisateur, AdminStoreUtilisateurRequest $request): void
    {
        match ($utilisateur->role) {
            'producteur' => Producteur::create([
                'utilisateur_id' => $utilisateur->id,
                'superficie'     => $request->superficie,
                'types_cultures' => $request->types_cultures,
                'region'         => $request->region,
            ]),
            'gestionnaire_entrepot' => Entrepot::create([
                'utilisateur_id' => $utilisateur->id,
                'nom_entrepot'   => $request->nom_entrepot,
                'capacite'       => $request->capacite,
                'localisation'   => $request->localisation,
            ]),
            'acheteur_gros' => AcheteurGros::create([
                'utilisateur_id'       => $utilisateur->id,
                'type_activite'        => $request->type_activite,
                'volume_achat_mensuel' => $request->volume_achat_mensuel,
            ]),
            'transporteur' => Transporteur::create([
                'utilisateur_id'  => $utilisateur->id,
                'type_vehicule'   => $request->type_vehicule,
                'capacite_charge' => $request->capacite_charge,
                'zone'            => $request->zone,
            ]),
            default => null, // administrateur : pas de profil métier
        };
    }
}
