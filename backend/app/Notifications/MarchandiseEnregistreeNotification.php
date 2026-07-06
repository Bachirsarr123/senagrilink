<?php

namespace App\Notifications;

use App\Models\Stock;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Déclenchée à l'étape 4 (enregistrement marchandise) lorsque le gestionnaire
 * valide la réception d'une marchandise en entrepôt. Non câblée avant que
 * cet endpoint n'existe.
 */
class MarchandiseEnregistreeNotification extends Notification
{
    public function __construct(private readonly Stock $stock)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'         => 'marchandise_enregistree',
            'stock_id'     => $this->stock->id,
            'entrepot_nom' => $this->stock->entrepot?->nom_entrepot,
            'produit'      => $this->stock->produit,
            'quantite'     => $this->stock->quantite,
            'message'      => sprintf(
                'Votre marchandise a été enregistrée à l\'entrepôt %s.',
                $this->stock->entrepot?->nom_entrepot ?? ''
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
