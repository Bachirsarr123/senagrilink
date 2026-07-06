<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ArriveeEntrepotNotification extends Notification
{
    public function __construct(private readonly Reservation $reservation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'               => 'arrivee_entrepot',
            'reservation_id'     => $this->reservation->id,
            'numero_reservation' => $this->reservation->numero_reservation,
            'produit'            => $this->reservation->produit,
            'message'            => sprintf(
                'Une marchandise est arrivée à l\'entrepôt pour la réservation %s. Vérifiez et enregistrez-la en stock.',
                $this->reservation->numero_reservation
            ),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
