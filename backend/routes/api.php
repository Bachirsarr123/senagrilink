<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// ─── Authentification broadcasting (canaux privés Reverb, via Sanctum) ───────
// Pas de 'prefix' ici : ce fichier est déjà préfixé par /api (voir bootstrap/app.php).
Broadcast::routes(['middleware' => ['auth:sanctum']]);

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

        // Réservation d'entrepôt
        Route::get('/entrepots/disponibles',    [\App\Http\Controllers\Api\ReservationController::class, 'entrepotsDisponibles']);
        Route::post('/reservations',            [\App\Http\Controllers\Api\ReservationController::class, 'store']);
    });

    // ── Module Gestionnaire d'entrepôt ────────────────────────────────────────
    Route::middleware('role:gestionnaire_entrepot')->group(function () {
        Route::get('/stocks',                   [\App\Http\Controllers\Api\StockController::class, 'index']);
        Route::post('/stocks/entree',           [\App\Http\Controllers\Api\StockController::class, 'entree']);
        Route::post('/stocks/sortie',           [\App\Http\Controllers\Api\StockController::class, 'sortie']);
        Route::get('/stocks/alertes',           [\App\Http\Controllers\Api\StockController::class, 'alertes']);
        Route::get('/stocks/rapport',           [\App\Http\Controllers\Api\StockController::class, 'rapport']);
        Route::put('/commandes/{id}/confirmer', [\App\Http\Controllers\Api\CommandeController::class, 'confirmer']);
        // Option B : création explicite de livraison après confirmation de commande
        Route::post('/livraisons',              [\App\Http\Controllers\Api\LivraisonController::class, 'store']);

        // Réservation d'entrepôt
        Route::put('/reservations/{id}/confirmer',              [\App\Http\Controllers\Api\ReservationController::class, 'confirmer']);
        Route::put('/reservations/{id}/assigner-transporteur',  [\App\Http\Controllers\Api\ReservationController::class, 'assignerTransporteur']);
        Route::put('/reservations/{id}/valider',                [\App\Http\Controllers\Api\ReservationController::class, 'validerMarchandise']);
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

        // Suivi GPS temps réel
        Route::post('/transporteur/position',           [\App\Http\Controllers\Api\PositionGpsController::class, 'store']);

        // Réservation d'entrepôt : acheminement de la marchandise
        Route::put('/reservations/{id}/arrivee',         [\App\Http\Controllers\Api\ReservationController::class, 'marquerArrivee']);
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

    // ── Suivi GPS (producteur/acheteur concernés + admin, filtré dans le contrôleur) ──
    Route::get('/livraisons/{id}/position', [\App\Http\Controllers\Api\PositionGpsController::class, 'showForLivraison']);

    // ── Réservations (lecture commune, filtrée par rôle dans le contrôleur) ───
    Route::get('/reservations', [\App\Http\Controllers\Api\ReservationController::class, 'index']);

    // ── Notifications (communes à tous les rôles authentifiés) ────────────────
    Route::get('/notifications',            [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::put('/notifications/{id}/lue',   [\App\Http\Controllers\Api\NotificationController::class, 'marquerLue']);
});
