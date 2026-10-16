<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // R3 — limitation des tentatives de connexion (réponse 429, compteur
        // réinitialisé après une minute). La clé combine l'e-mail saisi et l'IP,
        // que le compte existe ou non : la réponse ne révèle rien sur les comptes.
        // Une seconde limite par IP freine l'essai d'adresses multiples.
        RateLimiter::for('login', function (Request $request) {
            $reponse = fn () => response()->json([
                'message' => 'Trop de tentatives de connexion. Réessayez dans une minute.',
            ], 429);

            return [
                Limit::perMinute(5)
                    ->by('email:' . Str::lower((string) $request->input('email')) . '|' . $request->ip())
                    ->response($reponse),
                Limit::perMinute(20)
                    ->by('ip:' . $request->ip())
                    ->response($reponse),
            ];
        });
    }
}
