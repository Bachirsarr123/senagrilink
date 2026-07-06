<?php

namespace App\Notifications;

use App\Models\Commande;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class CommandeConfirmeeNotification extends Notification
{
    public function __construct(private readonly Commande $commande)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'            => 'commande_confirmee',
            'commande_id'     => $this->commande->id,
            'numero_commande' => $this->commande->numero_commande,
            'message'         => sprintf(
                'Votre commande %s a été confirmée.',
                $this->commande->numero_commande
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
