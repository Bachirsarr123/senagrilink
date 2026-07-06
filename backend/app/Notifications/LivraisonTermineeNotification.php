<?php

namespace App\Notifications;

use App\Models\Livraison;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class LivraisonTermineeNotification extends Notification
{
    public function __construct(private readonly Livraison $livraison)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'             => 'livraison_terminee',
            'livraison_id'     => $this->livraison->id,
            'numero_livraison' => $this->livraison->numero_livraison,
            'message'          => sprintf(
                'La livraison %s est arrivée à destination.',
                $this->livraison->numero_livraison
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
