<?php

namespace App\Listeners;

use App\Models\Utilisateur;
use App\Services\EnvoiPush;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Chaque notification enregistrée (canal « database ») part aussi sur le
 * téléphone de son destinataire, avec le même texte : une seule source pour
 * l'application, le site et les notifications push.
 */
class EnvoyerNotificationPush
{
    /** Titres courts par type de notification. */
    private const TITRES = [
        'nouvelle_commande' => 'Nouvelle commande', 'commande_confirmee' => 'Commande confirmée', 'commande_chargee' => 'Commande chargée',
        'livraison_en_cours' => 'Livraison en route', 'livraison_arrivee' => 'Livraison arrivée', 'livraison_terminee' => 'Livraison terminée',
        'mission_assignee' => 'Nouvelle mission', 'mission_retiree' => 'Mission retirée', 'mission_annulee' => 'Mission annulée',
        'mission_acceptee' => 'Mission acceptée', 'mission_refusee' => 'Mission refusée', 'incident' => 'Incident',
        'compte_a_valider' => 'Compte à valider', 'compte_valide' => 'Compte activé',
    ];

    public function __construct(private readonly EnvoiPush $push)
    {
    }

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof Utilisateur || ! EnvoiPush::configure()) {
            return;
        }

        try {
            $donnees = method_exists($event->notification, 'toDatabase')
                ? $event->notification->toDatabase($event->notifiable)
                : (method_exists($event->notification, 'toArray') ? $event->notification->toArray($event->notifiable) : []);
            $type = (string) ($donnees['type'] ?? '');
            $this->push->envoyer($event->notifiable, self::TITRES[$type] ?? 'SenAgriLink', (string) ($donnees['message'] ?? ''), $donnees);
        } catch (Throwable) {
            // Une notification push ne doit jamais faire échouer l'opération.
        }
    }
}
