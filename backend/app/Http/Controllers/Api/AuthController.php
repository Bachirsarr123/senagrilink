<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\AcheteurGros;
use App\Models\Entrepot;
use App\Models\Producteur;
use App\Models\Transporteur;
use App\Models\HistoriqueOperation;
use App\Models\Utilisateur;
use App\Notifications\OperationModifieeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
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
        HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'inscription', $utilisateur, null, $utilisateur->statut, null, ['role' => $utilisateur->role]);

        // Q9 : gestionnaires et transporteurs attendent la validation de l'administrateur, sans session ouverte.
        if (in_array($utilisateur->role, Utilisateur::ROLES_A_VALIDER, true)) {
            $utilisateur->update(['statut' => Utilisateur::EN_ATTENTE]);
            foreach (Utilisateur::where('role', 'administrateur')->where('statut', Utilisateur::ACTIF)->get() as $admin) {
                $admin->notify(new OperationModifieeNotification('compte_a_valider', sprintf(
                    'Nouveau compte à valider : %s %s (%s).', $utilisateur->prenom, $utilisateur->nom, $utilisateur->role === 'transporteur' ? 'transporteur' : 'gestionnaire d\'entrepôt',
                ), ['utilisateur_id' => $utilisateur->id]));
            }

            return response()->json([
                'message'             => 'Compte créé. Il sera activé après validation par l\'administrateur : vous serez prévenu.',
                'en_attente_validation' => true,
                'utilisateur'         => $utilisateur->only(['id', 'nom', 'prenom', 'email', 'role', 'statut']),
            ], 201);
        }

        $token = $utilisateur->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Inscription réussie.',
            'utilisateur'  => $utilisateur->load($this->relationProfil($utilisateur->role)),
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_at'   => $this->expirationJeton(),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $utilisateur = Utilisateur::where('email', $request->email)->first();

        if (! $utilisateur || ! Hash::check($request->mot_de_passe, $utilisateur->mot_de_passe)) {
            if ($utilisateur) {
                HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'echec_connexion', null);
            }

            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        if ($refus = $utilisateur->refusConnexion()) {
            HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'connexion_refusee', $utilisateur, null, null, null, ['statut' => $utilisateur->statut]);

            return response()->json($refus, 403);
        }

        HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'connexion', $utilisateur);
        $token = $utilisateur->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Connexion réussie.',
            'utilisateur'  => $utilisateur->load($this->relationProfil($utilisateur->role)),
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_at'   => $this->expirationJeton(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // L'appareil qui se déconnecte ne reçoit plus de notifications pour ce compte.
        if ($request->filled('jeton_appareil')) {
            $request->user()->appareils()->where('jeton', $request->input('jeton_appareil'))->delete();
        }
        HistoriqueOperation::consigner('utilisateur', $request->user()->id, 'deconnexion', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    public function me(Request $request): JsonResponse
    {
        $utilisateur = $request->user()->load($this->relationProfil($request->user()->role));

        return response()->json(['utilisateur' => $utilisateur]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($request->filled('mot_de_passe') && ! Hash::check((string) $request->input('mot_de_passe_actuel'), $utilisateur->mot_de_passe)) {
            return response()->json([
                'message' => 'Mot de passe actuel incorrect.',
                'errors'  => ['mot_de_passe_actuel' => ['Mot de passe actuel incorrect.']],
            ], 422);
        }

        DB::transaction(function () use ($utilisateur, $request) {
            $avant = $utilisateur->only(['nom', 'prenom', 'email', 'telephone', 'adresse']);
            $utilisateur->update($request->only(['nom', 'prenom', 'email', 'telephone', 'adresse', 'photo', 'moyen_paiement', 'numero_paiement']));
            $champs = array_keys(array_diff_assoc($utilisateur->only(array_keys($avant)), $avant));
            if ($champs || $request->filled('mot_de_passe')) {
                // Journal : quels champs ont changé (jamais les valeurs du mot de passe).
                HistoriqueOperation::consigner('utilisateur', $utilisateur->id, 'modification_profil', $utilisateur, null, null, null,
                    ['champs' => array_values(array_merge($champs, $request->filled('mot_de_passe') ? ['mot_de_passe'] : []))]);
            }

            // P-17 — le nouveau mot de passe était ignoré alors que l'écran affichait
            // un succès. Les autres sessions sont révoquées ; celle-ci reste ouverte.
            if ($request->filled('mot_de_passe')) {
                $utilisateur->update(['mot_de_passe' => $request->input('mot_de_passe')]);
                $jetonCourant = $request->user()->currentAccessToken();
                $autres       = $utilisateur->tokens();

                // Authentification par jeton personnel (cas de l'API) : on garde la
                // session courante. Autre garde (TransientToken) : tous les jetons sont révoqués.
                if ($jetonCourant instanceof PersonalAccessToken) {
                    $autres->whereKeyNot($jetonCourant->getKey());
                }

                $autres->delete();
            }
        });

        $this->mettreAJourProfil($utilisateur, $request);

        return response()->json([
            'message'     => 'Profil mis à jour.',
            'utilisateur' => $utilisateur->fresh()->load($this->relationProfil($utilisateur->role)),
        ]);
    }

    // -------------------------------------------------------------------------

    /** Date d'expiration du jeton qui vient d'être émis (config sanctum.expiration), ou null. */
    private function expirationJeton(): ?string
    {
        $minutes = config('sanctum.expiration');

        return $minutes ? now()->addMinutes($minutes)->toIso8601String() : null;
    }

    private function creerProfil(Utilisateur $utilisateur, RegisterRequest $request): void
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
            default => null,
        };
    }

    private function mettreAJourProfil(Utilisateur $utilisateur, UpdateProfileRequest $request): void
    {
        match ($utilisateur->role) {
            'producteur' => $utilisateur->producteur?->update($request->only(['superficie', 'types_cultures', 'region', 'latitude', 'longitude'])),
            'gestionnaire_entrepot' => $utilisateur->entrepot?->update($request->only(['nom_entrepot', 'capacite', 'localisation', 'latitude', 'longitude',
                'auto_accepter_depots', 'auto_confirmer_commandes', 'auto_affecter_transporteurs', 'delai_reponse_min', 'frais_stockage_kg'])),
            'acheteur_gros' => $utilisateur->acheteurGros?->update($request->only(['type_activite', 'volume_achat_mensuel', 'latitude', 'longitude'])),
            'transporteur' => $utilisateur->transporteur?->update($request->only(['type_vehicule', 'capacite_charge', 'zone'])),
            default => null,
        };
    }

    private function relationProfil(string $role): string
    {
        return match ($role) {
            'producteur'            => 'producteur',
            'gestionnaire_entrepot' => 'entrepot',
            'acheteur_gros'         => 'acheteurGros',
            'transporteur'          => 'transporteur',
            default                 => 'producteur', // administrateur : pas de profil métier
        };
    }
}
