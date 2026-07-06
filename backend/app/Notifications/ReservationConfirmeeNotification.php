<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Notification;

class ReservationConfirmeeNotification extends Notification
{
    public function __construct(private readonly Reservation $reservation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'               => 'reservation_confirmee',
            'reservation_id'     => $this->reservation->id,
            'numero_reservation' => $this->reservation->numero_reservation,
            'entrepot_id'        => $this->reservation->entrepot_id,
            'entrepot_nom'       => $this->reservation->entrepot?->nom_entrepot,
            'message'            => sprintf(
                'Votre réservation %s a été confirmée à l\'entrepôt %s.',
                $this->reservation->numero_reservation,
                $this->reservation->entrepot?->nom_entrepot ?? ''
            ),
        ];
    }
}
