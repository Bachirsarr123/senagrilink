<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête authentifiée d'un compte qui n'est pas actif (bloqué,
 * en attente de validation ou refusé), même avec un jeton
 * délivré avant le blocage. Complète la révocation des jetons faite par
 * AdminController::bloquer() (défense en profondeur).
 */
class EnsureCompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bloqué, en attente de validation (lot 6) ou refusé : aucune action possible.
        // Code stable : les clients distinguent ces cas d'un simple refus de permission.
        if ($refus = $request->user()?->refusConnexion()) {
            return response()->json($refus, 403);
        }

        return $next($request);
    }
}
