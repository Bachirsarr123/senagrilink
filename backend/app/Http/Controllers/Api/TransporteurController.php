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
        $transporteurs = Transporteur::with('utilisateur:id,nom,prenom,telephone')->get();

        return response()->json(['transporteurs' => $transporteurs]);
    }
}
