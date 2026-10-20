<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'un changement décidé par un autre acteur : mission de
 * transport assignée ou retirée, réservation ou commande refusée/annulée,
 * transporteur de livraison remplacé.
 */
class OperationModifieeNotification extends Notification
{
    public function __construct(
        private readonly string $type,
        private readonly string $message,
        private readonly array $donnees = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => $this->type, ...$this->donnees, 'message' => $this->message];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
