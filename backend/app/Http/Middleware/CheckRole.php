<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // L'administrateur a accès à tous les modules
        if ($utilisateur->role === 'administrateur') {
            return $next($request);
        }

        if (! in_array($utilisateur->role, $roles)) {
            return response()->json(['message' => 'Accès refusé. Rôle insuffisant.'], 403);
        }

        return $next($request);
    }
}
