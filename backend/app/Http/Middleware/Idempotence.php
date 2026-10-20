<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Création idempotente (P-25) : le client joint un en-tête Idempotency-Key
 * propre à chaque soumission de formulaire et le conserve s'il réessaie.
 *
 * - Première requête : traitée normalement ; une réponse 2xx est mémorisée.
 * - Même clé, même contenu, réponse mémorisée : la réponse d'origine est
 *   renvoyée (en-tête Idempotent-Replayed) sans rien créer de nouveau.
 * - Même clé pendant que la première requête est encore traitée : 409.
 * - Même clé avec un contenu différent : 422 (clé réutilisée par erreur).
 * - Échec (4xx/5xx) : rien n'est mémorisé, la soumission corrigée peut réessayer.
 *
 * Sans en-tête, la requête est traitée comme avant (compatibilité des clients).
 */
class Idempotence
{
    private const DUREE_CONSERVATION_HEURES = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $cle         = $request->header('Idempotency-Key');
        $utilisateur = $request->user();

        if ($cle === null || $utilisateur === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $cle)) {
            return response()->json(['message' => 'En-tête Idempotency-Key invalide.'], 400);
        }

        $empreinte = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        try {
            // Transaction imbriquée (point de sauvegarde) : sous PostgreSQL, une
            // violation d'unicité n'invalide pas une éventuelle transaction englobante.
            $trace = DB::transaction(fn () => DB::table('requetes_idempotentes')->insertGetId([
                'utilisateur_id' => $utilisateur->id,
                'cle'            => $cle,
                'empreinte'      => $empreinte,
                'created_at'     => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->rejouer($utilisateur->id, $cle, $empreinte);
        }

        $reponse = $next($request);

        if ($reponse->isSuccessful()) {
            DB::table('requetes_idempotentes')->where('id', $trace)->update([
                'statut_http' => $reponse->getStatusCode(),
                'reponse'     => $reponse->getContent(),
            ]);
        } else {
            DB::table('requetes_idempotentes')->where('id', $trace)->delete();
        }

        DB::table('requetes_idempotentes')
            ->where('utilisateur_id', $utilisateur->id)
            ->where('created_at', '<', now()->subHours(self::DUREE_CONSERVATION_HEURES))
            ->delete();

        return $reponse;
    }

    private function rejouer(int $utilisateurId, string $cle, string $empreinte): Response
    {
        $existante = DB::table('requetes_idempotentes')
            ->where('utilisateur_id', $utilisateurId)->where('cle', $cle)->first();

        if ($existante === null) {
            // Supprimée entre-temps (première tentative en échec) : le client peut réessayer.
            return response()->json(['message' => 'Requête précédente non aboutie : réessayez.'], 409);
        }

        if (! hash_equals($existante->empreinte, $empreinte)) {
            return response()->json([
                'message' => 'Cette opération a déjà été enregistrée avec d\'autres valeurs. Actualisez la page avant de recommencer.',
            ], 422);
        }

        if ($existante->statut_http === null) {
            return response()->json(['message' => 'Cette opération est déjà en cours de traitement.'], 409);
        }

        return response($existante->reponse, (int) $existante->statut_http)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }
}
