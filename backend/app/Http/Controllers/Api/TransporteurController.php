<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transporteur;
use Illuminate\Http\JsonResponse;

class TransporteurController extends Controller
{
    /**
     * Liste des transporteurs, pour que le gestionnaire puisse en assigner
     * un à une réservation (ou à une livraison).
     */
    public function index(): JsonResponse
    {
        // Seuls les transporteurs dont le compte n'est pas bloqué peuvent recevoir une mission.
        $transporteurs = Transporteur::with('utilisateur:id,nom,prenom,telephone')
            ->whereHas('utilisateur', fn ($q) => $q->where('statut', '!=', 'bloque'))
            ->get();

        return response()->json(['transporteurs' => $transporteurs]);
    }
}
