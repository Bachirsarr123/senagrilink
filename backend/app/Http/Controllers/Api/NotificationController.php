<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'notifications' => $request->user()->notifications()->latest()->get(),
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
}
