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
use App\Models\Utilisateur;
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
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        if ($utilisateur->statut === 'bloque') {
            return response()->json([
                'message' => 'Votre compte a été bloqué. Contactez l\'administrateur.',
                'code'    => 'compte_bloque',
            ], 403);
        }

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
            $utilisateur->update($request->only(['nom', 'prenom', 'email', 'telephone', 'adresse', 'photo']));

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
            'producteur' => $utilisateur->producteur?->update($request->only(['superficie', 'types_cultures', 'region'])),
            'gestionnaire_entrepot' => $utilisateur->entrepot?->update($request->only(['nom_entrepot', 'capacite', 'localisation', 'latitude', 'longitude'])),
            'acheteur_gros' => $utilisateur->acheteurGros?->update($request->only(['type_activite', 'volume_achat_mensuel'])),
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
