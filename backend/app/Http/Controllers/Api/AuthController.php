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
use Illuminate\Support\Facades\Hash;

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
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $utilisateur = Utilisateur::where('email', $request->email)->first();

        if (! $utilisateur || ! Hash::check($request->mot_de_passe, $utilisateur->mot_de_passe)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        if ($utilisateur->statut === 'bloque') {
            return response()->json(['message' => 'Votre compte a été bloqué. Contactez l\'administrateur.'], 403);
        }

        $token = $utilisateur->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'Connexion réussie.',
            'utilisateur'  => $utilisateur->load($this->relationProfil($utilisateur->role)),
            'access_token' => $token,
            'token_type'   => 'Bearer',
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

        $utilisateur->update($request->only(['nom', 'prenom', 'email', 'telephone', 'adresse', 'photo']));

        $this->mettreAJourProfil($utilisateur, $request);

        return response()->json([
            'message'     => 'Profil mis à jour.',
            'utilisateur' => $utilisateur->fresh()->load($this->relationProfil($utilisateur->role)),
        ]);
    }

    // -------------------------------------------------------------------------

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
