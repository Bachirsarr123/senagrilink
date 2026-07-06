<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Models\Entrepot;
use App\Models\Reservation;
use App\Notifications\ReservationConfirmeeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    // ── Producteur ─────────────────────────────────────────────────────────────

    /**
     * Entrepôts ayant de la capacité disponible (capacité totale - réservations
     * actives - stocks déjà entreposés).
     */
    public function entrepotsDisponibles(Request $request): JsonResponse
    {
        $entrepots = Entrepot::with('utilisateur:id,nom,prenom,telephone')
            ->get()
            ->map(fn (Entrepot $entrepot) => $this->avecCapaciteDisponible($entrepot))
            ->filter(fn (array $e) => $e['capacite_disponible'] > 0)
            ->values();

        return response()->json(['entrepots' => $entrepots]);
    }

    /**
     * Demander une réservation d'entrepôt.
     *
     * Règle métier : la quantité demandée ne peut excéder la capacité disponible.
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $producteur = $request->user()->producteur;

        if (! $producteur) {
            return response()->json(['message' => 'Profil producteur introuvable.'], 404);
        }

        $entrepot   = Entrepot::findOrFail($request->entrepot_id);
        $disponible = $this->capaciteDisponible($entrepot);

        if ($request->quantite_reservee > $disponible) {
            return response()->json([
                'message'             => 'Capacité insuffisante dans cet entrepôt. Réservation non créée.',
                'capacite_disponible' => $disponible,
                'quantite_demandee'   => $request->quantite_reservee,
            ], 422);
        }

        $reservation = Reservation::create([
            'entrepot_id'       => $entrepot->id,
            'producteur_id'     => $producteur->id,
            'production_id'     => $request->production_id,
            'produit'           => $request->produit,
            'quantite_reservee' => $request->quantite_reservee,
            'date_debut'        => $request->date_debut,
            'date_fin'          => $request->date_fin,
            'date_reservation'  => now(),
            'statut'            => 'en_attente',
        ]);

        return response()->json([
            'message'     => 'Demande de réservation envoyée.',
            'reservation' => $reservation->load('entrepot:id,nom_entrepot,localisation'),
        ], 201);
    }

    // ── Gestionnaire d'entrepôt ────────────────────────────────────────────────

    /**
     * Confirmer une réservation en_attente pour l'entrepôt du gestionnaire connecté.
     * Notifie automatiquement le producteur une fois confirmée.
     */
    public function confirmer(Request $request, int $id): JsonResponse
    {
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        if ($reservation->statut !== 'en_attente') {
            return response()->json([
                'message'       => 'Seules les réservations en attente peuvent être confirmées.',
                'statut_actuel' => $reservation->statut,
            ], 422);
        }

        // Re-vérification de la capacité au moment de la confirmation
        $disponible = $this->capaciteDisponible($reservation->entrepot, excluant: $reservation->id);

        if ($reservation->quantite_reservee > $disponible) {
            return response()->json([
                'message'             => 'Capacité insuffisante pour confirmer cette réservation.',
                'capacite_disponible' => $disponible,
                'quantite_demandee'   => $reservation->quantite_reservee,
            ], 422);
        }

        DB::transaction(function () use ($reservation) {
            $reservation->statut = 'confirmee';
            $reservation->save();
        });

        $reservation->producteur->utilisateur->notify(new ReservationConfirmeeNotification($reservation));

        return response()->json([
            'message'     => 'Réservation confirmée. Le producteur a été notifié.',
            'reservation' => $reservation->fresh()->load('entrepot:id,nom_entrepot'),
        ]);
    }

    // ── Commun ─────────────────────────────────────────────────────────────────

    /**
     * Liste des réservations : le producteur voit les siennes, le gestionnaire
     * celles de son entrepôt, l'administrateur voit tout.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $query = Reservation::with([
            'entrepot:id,nom_entrepot,localisation',
            'producteur:id,utilisateur_id',
            'producteur.utilisateur:id,nom,prenom,telephone',
        ])->orderByDesc('date_reservation');

        if ($utilisateur->role === 'producteur') {
            $producteur = $utilisateur->producteur;

            if (! $producteur) {
                return response()->json(['message' => 'Profil producteur introuvable.'], 404);
            }

            $query->where('producteur_id', $producteur->id);
        } elseif ($utilisateur->role === 'gestionnaire_entrepot') {
            $entrepot = $utilisateur->entrepot;

            if (! $entrepot) {
                return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
            }

            $query->where('entrepot_id', $entrepot->id);
        }
        // administrateur : aucun filtre, toutes les réservations

        return response()->json(['reservations' => $query->get()]);
    }

    // ── Helpers privés ─────────────────────────────────────────────────────────

    private function reservationDeLEntrepot(Request $request, int $id): Reservation|JsonResponse
    {
        $utilisateur = $request->user();
        $reservation = Reservation::with('entrepot')->find($id);

        if (! $reservation) {
            return response()->json(['message' => 'Réservation introuvable.'], 404);
        }

        if ($utilisateur->role === 'administrateur') {
            return $reservation;
        }

        $entrepot = $utilisateur->entrepot;

        if (! $entrepot || $reservation->entrepot_id !== $entrepot->id) {
            return response()->json(['message' => 'Accès non autorisé à cette réservation.'], 403);
        }

        return $reservation;
    }

    private function capaciteDisponible(Entrepot $entrepot, ?int $excluant = null): float
    {
        $reserve = Reservation::where('entrepot_id', $entrepot->id)
            ->whereIn('statut', ['en_attente', 'confirmee'])
            ->when($excluant, fn ($q) => $q->where('id', '!=', $excluant))
            ->sum('quantite_reservee');

        $stocke = $entrepot->stocks()->where('statut', 'disponible')->sum('quantite');

        return max(0, ($entrepot->capacite ?? 0) - $reserve - $stocke);
    }

    private function avecCapaciteDisponible(Entrepot $entrepot): array
    {
        return [
            'id'                  => $entrepot->id,
            'nom_entrepot'        => $entrepot->nom_entrepot,
            'localisation'        => $entrepot->localisation,
            'capacite'            => $entrepot->capacite,
            'capacite_disponible' => $this->capaciteDisponible($entrepot),
            'gestionnaire'        => $entrepot->utilisateur,
        ];
    }
}
