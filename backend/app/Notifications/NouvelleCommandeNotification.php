<?php

namespace App\Notifications;

use App\Models\Commande;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NouvelleCommandeNotification extends Notification
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
            'type'            => 'nouvelle_commande',
            'commande_id'     => $this->commande->id,
            'numero_commande' => $this->commande->numero_commande,
            'produit'         => $this->commande->produit,
            'quantite'        => $this->commande->quantite,
            'message'         => sprintf(
                'Nouvelle commande %s : %s (%s kg).',
                $this->commande->numero_commande,
                $this->commande->produit,
                $this->commande->quantite
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
