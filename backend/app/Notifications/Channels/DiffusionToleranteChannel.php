<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal « broadcast » tolérant aux pannes (P-01).
 *
 * La diffusion temps réel (Reverb) n'est qu'un confort : la notification est
 * déjà enregistrée en base (canal « database », envoyé avant) et l'action
 * métier est validée. Un serveur temps réel absent ou injoignable ne doit donc
 * ni faire répondre une erreur à l'utilisateur, ni empêcher la notification des
 * destinataires suivants : l'échec est seulement journalisé.
 */
class DiffusionToleranteChannel extends BroadcastChannel
{
    public function send($notifiable, $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $e) {
            // Message tronqué avant l'URL signée renvoyée par le client Pusher.
            Log::warning('Diffusion temps réel impossible ; notification conservée en base.', [
                'notification' => $notification::class,
                'destinataire' => $notifiable->getKey(),
                'erreur'       => explode(' for http', $e->getMessage(), 2)[0],
            ]);

            return null;
        }
    }
}
