<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PropositionTransporteur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TransporteurController extends Controller
{
    /**
     * Liste des transporteurs, pour que le gestionnaire en confie un à un dépôt
     * ou à une livraison. Avec ?latitude&longitude (lieu de prise en charge) :
     * libres d'abord, puis les plus proches, puis les moins occupés (lot 9).
     */
    public function index(Request $request): JsonResponse
    {
        $lat = is_numeric($request->query('latitude')) ? (float) $request->query('latitude') : null;
        $lng = is_numeric($request->query('longitude')) ? (float) $request->query('longitude') : null;

        return response()->json(['transporteurs' => PropositionTransporteur::classer($lat, $lng)]);
    }
}
