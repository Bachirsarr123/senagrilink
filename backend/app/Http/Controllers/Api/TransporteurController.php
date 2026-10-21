<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transporteur;
use Illuminate\Http\JsonResponse;

class TransporteurController extends Controller
{
    /** Statuts de réservation où le transporteur est engagé dans un acheminement. */
    private const ACHEMINEMENTS_ACTIFS = ['confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'];

    /**
     * Liste des transporteurs, pour que le gestionnaire puisse en assigner
     * un à une réservation (ou à une livraison). Les moins occupés d'abord :
     * « missions_actives » compte ses acheminements et livraisons non terminés.
     */
    public function index(): JsonResponse
    {
        // Seuls les transporteurs dont le compte n'est pas bloqué peuvent recevoir une mission.
        $transporteurs = Transporteur::with('utilisateur:id,nom,prenom,telephone')
            ->whereHas('utilisateur', fn ($q) => $q->where('statut', '!=', 'bloque'))
            ->withCount([
                'reservations as acheminements_actifs' => fn ($q) => $q->whereIn('statut', self::ACHEMINEMENTS_ACTIFS),
                'livraisons as livraisons_actives'      => fn ($q) => $q->whereIn('statut', ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee']),
            ])
            ->get()
            ->each(fn (Transporteur $t) => $t->setAttribute('missions_actives', $t->acheminements_actifs + $t->livraisons_actives))
            ->sortBy([['missions_actives', 'asc'], ['id', 'asc']])
            ->values();

        return response()->json(['transporteurs' => $transporteurs]);
    }
}
