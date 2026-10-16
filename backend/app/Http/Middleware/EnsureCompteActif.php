<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête authentifiée d'un compte bloqué, même avec un jeton
 * délivré avant le blocage. Complète la révocation des jetons faite par
 * AdminController::bloquer() (défense en profondeur).
 */
class EnsureCompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->statut === 'bloque') {
            return response()->json([
                'message' => 'Votre compte a été bloqué. Contactez l\'administrateur.',
            ], 403);
        }

        return $next($request);
    }
}
