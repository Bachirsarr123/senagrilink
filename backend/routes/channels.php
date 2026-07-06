<?php

use App\Models\Utilisateur;
use Illuminate\Support\Facades\Broadcast;

// Canal privé des notifications personnelles (voir Utilisateur::receivesBroadcastNotificationsOn()).
Broadcast::channel('utilisateur.{id}', function (Utilisateur $utilisateur, int $id) {
    return (int) $utilisateur->id === $id;
});
