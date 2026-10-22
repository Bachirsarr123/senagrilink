<?php

namespace App\Services;

use App\Models\Appareil;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications sur téléphone par Firebase Cloud Messaging, API HTTP v1
 * (lot 6, Q15). Sans compte de service Firebase configuré, rien n'est
 * envoyé : les notifications restent visibles dans l'application et sur le
 * site. Un envoi qui échoue n'interrompt jamais l'opération en cours.
 *
 * Configuration : FIREBASE_CREDENTIALS_BASE64 = fichier JSON du compte de
 * service Firebase, encodé en base64 (secret, à saisir dans Render).
 */
class EnvoiPush
{
    private const PORTEE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function configure(): bool
    {
        return self::compte() !== null;
    }

    /** Envoie à tous les appareils de l'utilisateur ; renvoie le nombre d'envois réussis. */
    public function envoyer(Utilisateur $u, string $titre, string $message, array $donnees = []): int
    {
        $compte = self::compte();
        if (! $compte) {
            return 0;
        }

        $reussis = 0;
        foreach ($u->appareils()->get() as $appareil) {
            try {
                $reponse = Http::timeout(5)->withToken($this->jetonAcces($compte))
                    ->post("https://fcm.googleapis.com/v1/projects/{$compte['project_id']}/messages:send", ['message' => [
                        'token'        => $appareil->jeton,
                        'notification' => ['title' => $titre, 'body' => mb_strimwidth($message, 0, 240, '…')],
                        // Valeurs texte uniquement (exigence FCM)
                        'data'         => array_map('strval', array_filter($donnees, fn ($v) => is_scalar($v))),
                        'android'      => ['priority' => 'high'],
                    ]]);

                if ($reponse->successful()) {
                    $reussis++;
                    $appareil->update(['derniere_activite' => now()]);
                } elseif (in_array($reponse->status(), [404, 410], true) || str_contains($reponse->body(), 'UNREGISTERED')) {
                    $appareil->delete(); // application désinstallée ou jeton périmé
                }
            } catch (Throwable $e) {
                Log::warning('Notification Firebase non envoyée', ['appareil' => $appareil->id, 'erreur' => $e->getMessage()]);
            }
        }

        return $reussis;
    }

    /** Compte de service décodé (base64, ou fichier JSON collé tel quel), ou null si absent ou illisible. */
    private static function compte(): ?array
    {
        $brut = trim((string) config('services.firebase.credentials_base64'));
        if ($brut === '') {
            return null;
        }
        $json = str_starts_with($brut, '{') ? $brut : (base64_decode($brut, true) ?: '');
        $compte = json_decode($json, true);

        return isset($compte['project_id'], $compte['client_email'], $compte['private_key']) ? $compte : null;
    }

    /** Jeton OAuth 2 du compte de service (JWT signé RS256), gardé 50 minutes. */
    private function jetonAcces(array $compte): string
    {
        return Cache::remember('firebase_jeton_'.hash('sha256', $compte['client_email']), 3000, function () use ($compte) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $maintenant = time();
            $entete = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $corps  = $b64(json_encode([
                'iss' => $compte['client_email'], 'scope' => self::PORTEE, 'aud' => $compte['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $maintenant, 'exp' => $maintenant + 3600,
            ]));
            openssl_sign("{$entete}.{$corps}", $signature, $compte['private_key'], 'sha256WithRSAEncryption');

            return Http::asForm()->timeout(5)->post($compte['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "{$entete}.{$corps}.".$b64($signature),
            ])->throw()->json('access_token');
        });
    }
}
