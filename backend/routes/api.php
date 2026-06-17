<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// ─── Authentification (public) ───────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// ─── Routes protégées ────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout',    [AuthController::class, 'logout']);
    Route::get('/me',         [AuthController::class, 'me']);
    Route::put('/profile',    [AuthController::class, 'updateProfile']);

    // ── Module Producteur ─────────────────────────────────────────────────────
    Route::middleware('role:producteur')->group(function () {
        Route::get('/productions',              [\App\Http\Controllers\Api\ProductionController::class, 'index']);
        Route::post('/productions',             [\App\Http\Controllers\Api\ProductionController::class, 'store']);
        Route::put('/productions/{id}',         [\App\Http\Controllers\Api\ProductionController::class, 'update']);
        Route::get('/commandes/disponibles',    [\App\Http\Controllers\Api\ProductionController::class, 'commandesDisponibles']);
    });

    // ── Module Gestionnaire d'entrepôt ────────────────────────────────────────
    Route::middleware('role:gestionnaire_entrepot')->group(function () {
        Route::get('/stocks',                   [\App\Http\Controllers\Api\StockController::class, 'index']);
        Route::post('/stocks/entree',           [\App\Http\Controllers\Api\StockController::class, 'entree']);
        Route::post('/stocks/sortie',           [\App\Http\Controllers\Api\StockController::class, 'sortie']);
        Route::get('/stocks/alertes',           [\App\Http\Controllers\Api\StockController::class, 'alertes']);
        Route::get('/stocks/rapport',           [\App\Http\Controllers\Api\StockController::class, 'rapport']);
        Route::put('/commandes/{id}/confirmer', [\App\Http\Controllers\Api\CommandeController::class, 'confirmer']);
    });

    // ── Module Acheteur en gros ───────────────────────────────────────────────
    Route::middleware('role:acheteur_gros')->group(function () {
        Route::get('/catalogue',                [\App\Http\Controllers\Api\CommandeController::class, 'catalogue']);
        Route::post('/commandes',               [\App\Http\Controllers\Api\CommandeController::class, 'store']);
        Route::put('/commandes/{id}',           [\App\Http\Controllers\Api\CommandeController::class, 'update']);
        Route::delete('/commandes/{id}',        [\App\Http\Controllers\Api\CommandeController::class, 'destroy']);
        Route::get('/commandes/historique',     [\App\Http\Controllers\Api\CommandeController::class, 'historique']);
    });

    // ── Module Transporteur ───────────────────────────────────────────────────
    Route::middleware('role:transporteur')->group(function () {
        Route::get('/livraisons',                       [\App\Http\Controllers\Api\LivraisonController::class, 'index']);
        Route::put('/livraisons/{id}/statut',           [\App\Http\Controllers\Api\LivraisonController::class, 'updateStatut']);
        Route::post('/livraisons/{id}/probleme',        [\App\Http\Controllers\Api\LivraisonController::class, 'signalerProbleme']);
    });

    // ── Module Administrateur ─────────────────────────────────────────────────
    Route::middleware('role:administrateur')->prefix('admin')->group(function () {
        Route::get('/utilisateurs',             [\App\Http\Controllers\Api\AdminController::class, 'indexUtilisateurs']);
        Route::post('/utilisateurs',            [\App\Http\Controllers\Api\AdminController::class, 'storeUtilisateur']);
        Route::put('/utilisateurs/{id}/bloquer',   [\App\Http\Controllers\Api\AdminController::class, 'bloquer']);
        Route::put('/utilisateurs/{id}/debloquer', [\App\Http\Controllers\Api\AdminController::class, 'debloquer']);
        Route::get('/dashboard',               [\App\Http\Controllers\Api\AdminController::class, 'dashboard']);
    });

    // ── Module Traçabilité (tous les rôles authentifiés) ──────────────────────
    Route::get('/tracabilite/{code_tracabilite}', [\App\Http\Controllers\Api\TracabiliteController::class, 'show']);
});
