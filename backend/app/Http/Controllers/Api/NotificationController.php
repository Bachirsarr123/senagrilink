<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appareil;
use App\Services\EnvoiPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Notifications de l'utilisateur connecté (les plus récentes en premier).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            // Les 100 plus récentes : la liste reste rapide même après des mois d'usage.
            'notifications' => $request->user()->notifications()->latest()->limit(100)->get(),
            'non_lues'      => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marquer une notification comme lue.
     */
    public function marquerLue(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    /** Tout marquer comme lu. */
    public function toutMarquerLu(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications sont marquées comme lues.', 'non_lues' => 0]);
    }

    /**
     * Enregistre le téléphone de l'utilisateur pour les notifications Firebase
     * (Q15). Un jeton déjà connu change de propriétaire (téléphone partagé, ou
     * changement de compte) : il n'avertit jamais deux personnes.
     */
    public function enregistrerAppareil(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'jeton'      => ['required', 'string', 'max:512'],
            'plateforme' => ['nullable', 'in:android,ios,web'],
        ]);

        Appareil::updateOrCreate(['jeton' => $donnees['jeton']], [
            'utilisateur_id'    => $request->user()->id,
            'plateforme'        => $donnees['plateforme'] ?? 'android',
            'derniere_activite' => now(),
        ]);

        return response()->json(['message' => 'Appareil enregistré pour les notifications.', 'push_actif' => EnvoiPush::configure()]);
    }

    public function retirerAppareil(Request $request): JsonResponse
    {
        $request->validate(['jeton' => ['required', 'string', 'max:512']]);
        $request->user()->appareils()->where('jeton', $request->input('jeton'))->delete();

        return response()->json(['message' => 'Appareil retiré.']);
    }
}
