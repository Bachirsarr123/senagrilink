<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// ─── Authentification broadcasting (canaux privés Reverb, via Sanctum) ───────
// Pas de 'prefix' ici : ce fichier est déjà préfixé par /api (voir bootstrap/app.php).
Broadcast::routes(['middleware' => ['auth:sanctum', 'compte.actif']]);

// ─── Authentification (public) ───────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:login');

// ─── Routes protégées (jeton valide + compte non bloqué) ─────────────────────
Route::middleware(['auth:sanctum', 'compte.actif'])->group(function () {

    Route::post('/logout',    [AuthController::class, 'logout']);
    Route::get('/me',         [AuthController::class, 'me']);
    Route::put('/profile',    [AuthController::class, 'updateProfile']);

    // ── Module Producteur ─────────────────────────────────────────────────────
    Route::middleware('role:producteur')->group(function () {
        Route::get('/productions',              [\App\Http\Controllers\Api\ProductionController::class, 'index']);
        Route::post('/productions',             [\App\Http\Controllers\Api\ProductionController::class, 'store'])->middleware('idempotence');
        Route::put('/productions/{id}',         [\App\Http\Controllers\Api\ProductionController::class, 'update']);
        Route::put('/productions/{id}/annuler', [\App\Http\Controllers\Api\ProductionController::class, 'annuler']);
        Route::get('/commandes/disponibles',    [\App\Http\Controllers\Api\ProductionController::class, 'commandesDisponibles']);

        // Réservation d'entrepôt
        Route::get('/entrepots/disponibles',    [\App\Http\Controllers\Api\ReservationController::class, 'entrepotsDisponibles']);
        Route::post('/reservations',            [\App\Http\Controllers\Api\ReservationController::class, 'store'])->middleware('idempotence');
        Route::put('/reservations/{id}/annuler', [\App\Http\Controllers\Api\ReservationController::class, 'annuler']);
    });

    // ── Module Gestionnaire d'entrepôt ────────────────────────────────────────
    Route::middleware('role:gestionnaire_entrepot')->group(function () {
        Route::get('/stocks',                   [\App\Http\Controllers\Api\StockController::class, 'index']);
        Route::get('/stocks/alertes',           [\App\Http\Controllers\Api\StockController::class, 'alertes']);
        Route::get('/stocks/rapport',           [\App\Http\Controllers\Api\StockController::class, 'rapport']);
        // Plus d'entrée ni de sortie libre : réception (entrée), départ de livraison (sortie), ajustement motivé.
        Route::post('/stocks/{id}/ajustement',  [\App\Http\Controllers\Api\StockController::class, 'ajuster'])->whereNumber('id')->middleware('idempotence');
        Route::put('/stocks/{id}/seuil',        [\App\Http\Controllers\Api\StockController::class, 'seuil'])->whereNumber('id');
        Route::get('/stocks/{id}/mouvements',   [\App\Http\Controllers\Api\StockController::class, 'mouvements'])->whereNumber('id');
        Route::get('/commandes',                [\App\Http\Controllers\Api\CommandeController::class, 'indexEntrepot']);
        Route::put('/commandes/{id}/confirmer', [\App\Http\Controllers\Api\CommandeController::class, 'confirmer']);
        Route::put('/commandes/{id}/refuser',   [\App\Http\Controllers\Api\CommandeController::class, 'refuser']);
        Route::put('/commandes/{id}/annuler-confirmation', [\App\Http\Controllers\Api\CommandeController::class, 'annulerConfirmation']);
        // Option B : création explicite de livraison après confirmation de commande
        Route::post('/livraisons',              [\App\Http\Controllers\Api\LivraisonController::class, 'store'])->middleware('idempotence');
        Route::put('/livraisons/{id}/transporteur', [\App\Http\Controllers\Api\LivraisonController::class, 'changerTransporteur']);

        // Réservation d'entrepôt
        Route::put('/reservations/{id}/confirmer',              [\App\Http\Controllers\Api\ReservationController::class, 'confirmer']);
        Route::put('/reservations/{id}/assigner-transporteur',  [\App\Http\Controllers\Api\ReservationController::class, 'assignerTransporteur']);
        Route::delete('/reservations/{id}/transporteur',        [\App\Http\Controllers\Api\ReservationController::class, 'retirerTransporteur']);
        Route::put('/reservations/{id}/refuser',                [\App\Http\Controllers\Api\ReservationController::class, 'refuser']);
        Route::put('/reservations/{id}/remettre-en-attente',    [\App\Http\Controllers\Api\ReservationController::class, 'remettreEnAttente']);
        Route::put('/reservations/{id}/valider',                [\App\Http\Controllers\Api\ReservationController::class, 'validerMarchandise']);
        Route::put('/reservations/{id}/refuser-reception',      [\App\Http\Controllers\Api\ReservationController::class, 'refuserReception']);
        Route::get('/transporteurs',                            [\App\Http\Controllers\Api\TransporteurController::class, 'index']);
    });

    // ── Module Acheteur en gros ───────────────────────────────────────────────
    Route::middleware('role:acheteur_gros')->group(function () {
        Route::get('/catalogue',                [\App\Http\Controllers\Api\CommandeController::class, 'catalogue']);
        Route::post('/commandes',               [\App\Http\Controllers\Api\CommandeController::class, 'store'])->middleware('idempotence');
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
        Route::put('/reservations/{id}/accepter-mission', [\App\Http\Controllers\Api\ReservationController::class, 'accepterMission']);
        Route::put('/reservations/{id}/refuser-mission',  [\App\Http\Controllers\Api\ReservationController::class, 'refuserMission']);
        Route::put('/reservations/{id}/arrivee',         [\App\Http\Controllers\Api\ReservationController::class, 'marquerArrivee']);
    });

    // ── Module Administrateur ─────────────────────────────────────────────────
    Route::middleware('role:administrateur')->prefix('admin')->group(function () {
        Route::get('/utilisateurs',             [\App\Http\Controllers\Api\AdminController::class, 'indexUtilisateurs']);
        Route::post('/utilisateurs',            [\App\Http\Controllers\Api\AdminController::class, 'storeUtilisateur'])->middleware('idempotence');
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
    // Étapes de transport : transporteur de la mission ou producteur qui livre lui-même (vérifié dans le contrôleur)
    Route::put('/reservations/{id}/etape', [\App\Http\Controllers\Api\ReservationController::class, 'etape']);

    // ── Notifications (communes à tous les rôles authentifiés) ────────────────
    Route::get('/notifications',            [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::put('/notifications/{id}/lue',   [\App\Http\Controllers\Api\NotificationController::class, 'marquerLue']);
});
