<?php

namespace App\Http\Middleware;

use App\Services\PiloteAutomatique;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot 11 : relance des missions sans réponse, après l'envoi de la réponse (au
 * plus une fois par minute). Render gratuit n'a pas de tâche planifiée : ce sont
 * les requêtes des utilisateurs qui font avancer le pilote automatique.
 */
class RelancePilote
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! app()->runningUnitTests()) {
            app(PiloteAutomatique::class)->relancerSiNecessaire();
        }
    }
}
