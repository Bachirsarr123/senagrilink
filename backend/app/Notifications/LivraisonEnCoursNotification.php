<?php

namespace App\Notifications;

use App\Models\Livraison;
use App\Models\PositionGps;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class LivraisonEnCoursNotification extends Notification
{
    public function __construct(
        private readonly Livraison $livraison,
        private readonly ?PositionGps $position = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'             => 'livraison_en_cours',
            'livraison_id'     => $this->livraison->id,
            'numero_livraison' => $this->livraison->numero_livraison,
            'position'         => $this->position ? [
                'latitude'  => $this->position->latitude,
                'longitude' => $this->position->longitude,
                'timestamp' => $this->position->timestamp,
            ] : null,
            'message' => sprintf(
                'Votre livraison %s est en cours.',
                $this->livraison->numero_livraison
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
