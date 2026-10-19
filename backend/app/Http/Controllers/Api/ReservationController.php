<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignerTransporteurReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\ValiderMarchandiseRequest;
use App\Models\Entrepot;
use App\Models\HistoriqueOperation;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Transporteur;
use App\Notifications\ArriveeEntrepotNotification;
use App\Notifications\MarchandiseEnregistreeNotification;
use App\Notifications\OperationModifieeNotification;
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

    /**
     * Annulation par le producteur de sa propre demande, tant qu'aucun
     * transporteur n'est engagé (en attente, ou confirmée sans transporteur).
     */
    public function annuler(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $producteur  = $request->user()->producteur;
        $reservation = Reservation::with('entrepot.utilisateur')->find($id);

        if (! $reservation) {
            return response()->json(['message' => 'Réservation introuvable.'], 404);
        }

        if (! $producteur || $reservation->producteur_id !== $producteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette réservation.'], 403);
        }

        $resultat = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->statut === 'confirmee' && $r->transporteur_id) {
                return $this->refus('Un transporteur est déjà assigné à cette réservation : demandez à l\'entrepôt de retirer la mission avant d\'annuler.', $r);
            }

            if (! in_array($r->statut, ['en_attente', 'confirmee'], true)) {
                return $this->refus('Cette réservation ne peut plus être annulée.', $r);
            }

            $ancien = $r->statut;
            $r->statut = 'annulee';
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, 'annulation', $request->user(), $ancien, 'annulee', $request->input('motif'));

            return null;
        });

        if ($resultat) {
            return $resultat;
        }

        $reservation->entrepot?->utilisateur?->notify(new OperationModifieeNotification('reservation_annulee', sprintf(
            'Le producteur a annulé la réservation %s (%s, %s kg).',
            $reservation->numero_reservation, $reservation->produit, $reservation->quantite_reservee,
        ), ['reservation_id' => $reservation->id, 'numero_reservation' => $reservation->numero_reservation]));

        return response()->json(['message' => 'Réservation annulée. L\'entrepôt a été notifié.', 'reservation' => $reservation->fresh()]);
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

    /**
     * Assigner (ou réassigner) un transporteur à une réservation confirmée, pour
     * l'acheminement de la marchandise du producteur vers l'entrepôt.
     *
     * Possible tant que la marchandise n'est pas arrivée (statut confirmee).
     * La mission apparaît chez le nouveau transporteur (GET /reservations, filtré
     * par transporteur_id) et il est notifié ; l'ancien la perd et est notifié.
     */
    public function assignerTransporteur(AssignerTransporteurReservationRequest $request, int $id): JsonResponse
    {
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $transporteur = Transporteur::with('utilisateur')->find($request->transporteur_id);

        if ($transporteur->utilisateur?->statut === 'bloque') {
            return response()->json(['message' => 'Ce transporteur ne peut pas recevoir de mission (compte bloqué).'], 422);
        }

        $resultat = DB::transaction(function () use ($reservation, $transporteur, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->statut !== 'confirmee') {
                return $this->refus('Seules les réservations confirmées, dont la marchandise n\'est pas encore arrivée, peuvent recevoir un transporteur.', $r);
            }

            $ancien = $r->transporteur_id;

            if ($ancien === $transporteur->id) {
                return response()->json(['message' => 'Ce transporteur est déjà assigné à cette réservation.'], 422);
            }

            $r->transporteur_id = $transporteur->id;
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, $ancien ? 'reassignation_transporteur' : 'assignation_transporteur',
                $request->user(), $r->statut, $r->statut, $request->input('motif'),
                ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $transporteur->id]);

            return ['ancien' => $ancien];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $reservation->refresh()->load('entrepot', 'producteur.utilisateur');
        $transporteur->utilisateur?->notify(new OperationModifieeNotification('mission_assignee', sprintf(
            'Nouvelle mission : acheminer %s (%s kg) de %s vers l\'entrepôt %s — réservation %s.',
            $reservation->produit, $reservation->quantite_reservee,
            trim(($reservation->producteur?->utilisateur?->prenom ?? '').' '.($reservation->producteur?->utilisateur?->nom ?? '')),
            $reservation->entrepot?->nom_entrepot ?? '', $reservation->numero_reservation,
        ), ['reservation_id' => $reservation->id, 'numero_reservation' => $reservation->numero_reservation]));
        $this->notifierRetraitMission($resultat['ancien'], $reservation);

        return response()->json([
            'message'     => $resultat['ancien'] ? 'Mission réassignée au nouveau transporteur.' : 'Transporteur assigné à la réservation.',
            'reservation' => $reservation->fresh()->load('transporteur.utilisateur:id,nom,prenom,telephone'),
        ]);
    }

    /** Retirer le transporteur d'une réservation dont la marchandise n'est pas encore arrivée. */
    public function retirerTransporteur(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $resultat = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->statut !== 'confirmee' || ! $r->transporteur_id) {
                return $this->refus('Aucune mission en cours à retirer : la marchandise est déjà arrivée ou aucun transporteur n\'est assigné.', $r);
            }

            $ancien = $r->transporteur_id;
            $r->transporteur_id = null;
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, 'retrait_transporteur', $request->user(),
                $r->statut, $r->statut, $request->input('motif'), ['ancien_transporteur_id' => $ancien]);

            return ['ancien' => $ancien];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $this->notifierRetraitMission($resultat['ancien'], $reservation->refresh());

        return response()->json([
            'message'     => 'Transporteur retiré. La mission n\'apparaît plus dans son espace.',
            'reservation' => $reservation->fresh(),
        ]);
    }

    /** Refuser une demande de réservation en attente (motif obligatoire, producteur notifié). */
    public function refuser(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif du refus.']);

        return $this->changerStatutParLEntrepot($request, $id, ['en_attente'], 'refusee', 'refus',
            'Seules les demandes en attente peuvent être refusées.',
            'Votre demande de réservation %s a été refusée par l\'entrepôt %s. Motif : %s',
            'Réservation refusée. Le producteur a été notifié.');
    }

    /** Annuler la confirmation (erreur de manipulation) : retour en attente, sans transporteur assigné. */
    public function remettreEnAttente(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:500']]);

        return $this->changerStatutParLEntrepot($request, $id, ['confirmee'], 'en_attente', 'retour_en_attente',
            'Seule une réservation confirmée, sans transporteur assigné, peut revenir en attente.',
            'La confirmation de votre réservation %s à l\'entrepôt %s a été annulée : elle est de nouveau en attente. %s',
            'Réservation remise en attente. Le producteur a été notifié.');
    }

    /**
     * Valider l'enregistrement d'une marchandise arrivée à l'entrepôt.
     *
     * Après vérification (quantité, qualité), crée l'entrée de stock avec
     * production_id pour la traçabilité, et notifie le producteur.
     */
    public function validerMarchandise(ValiderMarchandiseRequest $request, int $id): JsonResponse
    {
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        if ($reservation->statut !== 'arrivee_entrepot') {
            return response()->json([
                'message'       => 'Seule une marchandise arrivée à l\'entrepôt peut être enregistrée.',
                'statut_actuel' => $reservation->statut,
            ], 422);
        }

        $stock = DB::transaction(function () use ($reservation, $request) {
            $stock = Stock::create([
                'entrepot_id'   => $reservation->entrepot_id,
                'production_id' => $reservation->production_id,
                'produit'       => $reservation->produit,
                'quantite'      => $request->quantite_reelle,
                'date_entree'   => now()->toDateString(),
                'observation'   => $request->observation,
                'statut'        => 'disponible',
            ]);

            $reservation->statut = 'enregistree';
            $reservation->save();

            // La récolte liée est désormais physiquement en entrepôt.
            $reservation->production?->update(['statut' => 'disponible']);

            return $stock;
        });

        $reservation->producteur->utilisateur->notify(new MarchandiseEnregistreeNotification($stock->load('entrepot')));

        return response()->json([
            'message'     => 'Marchandise enregistrée en stock. Le producteur a été notifié.',
            'stock'       => $stock->load('production:id,code_tracabilite,type_culture'),
            'reservation' => $reservation->fresh(),
        ], 201);
    }

    // ── Transporteur ───────────────────────────────────────────────────────────

    /**
     * Marquer l'arrivée de la marchandise à l'entrepôt.
     * Notifie automatiquement le gestionnaire pour vérification et enregistrement.
     */
    public function marquerArrivee(Request $request, int $id): JsonResponse
    {
        $reservation = $this->reservationDuTransporteur($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        if ($reservation->statut !== 'confirmee') {
            return response()->json([
                'message'       => 'Seule une réservation confirmée peut être marquée comme arrivée.',
                'statut_actuel' => $reservation->statut,
            ], 422);
        }

        $reservation->statut = 'arrivee_entrepot';
        $reservation->save();

        $reservation->entrepot->utilisateur?->notify(new ArriveeEntrepotNotification($reservation));

        return response()->json([
            'message'     => 'Arrivée signalée. Le gestionnaire a été notifié.',
            'reservation' => $reservation->fresh(),
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
            'transporteur:id,utilisateur_id,type_vehicule',
            'transporteur.utilisateur:id,nom,prenom,telephone',
        ])->orderByDesc('date_reservation');

        // Historique des corrections : utile au producteur, au gestionnaire et à
        // l'administrateur ; non exposé au transporteur (il ne concerne pas que lui).
        if ($utilisateur->role !== 'transporteur') {
            $query->with('historique.utilisateur:id,nom,prenom,role');
        }

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
        } elseif ($utilisateur->role === 'transporteur') {
            $transporteur = $utilisateur->transporteur;

            if (! $transporteur) {
                return response()->json(['message' => 'Profil transporteur introuvable.'], 404);
            }

            $query->where('transporteur_id', $transporteur->id);
        }
        // administrateur : aucun filtre, toutes les réservations

        return response()->json(['reservations' => $query->get()]);
    }

    // ── Helpers privés ─────────────────────────────────────────────────────────

    private function refus(string $message, Reservation $reservation): JsonResponse
    {
        return response()->json(['message' => $message, 'statut_actuel' => $reservation->statut], 422);
    }

    /** L'ancien transporteur ne voit plus la mission : il en est informé. */
    private function notifierRetraitMission(?int $transporteurId, Reservation $reservation): void
    {
        if (! $transporteurId) {
            return;
        }

        Transporteur::with('utilisateur')->find($transporteurId)?->utilisateur?->notify(new OperationModifieeNotification(
            'mission_retiree',
            sprintf('La mission d\'acheminement %s vous a été retirée par l\'entrepôt.', $reservation->numero_reservation),
            ['reservation_id' => $reservation->id, 'numero_reservation' => $reservation->numero_reservation],
        ));
    }

    /**
     * Transition décidée par le gestionnaire (refus, retour en attente) : statut
     * revérifié sous verrou, historique et notification du producteur.
     */
    private function changerStatutParLEntrepot(Request $request, int $id, array $depuis, string $vers, string $action,
        string $messageRefus, string $messageProducteur, string $messageSucces): JsonResponse
    {
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $motif = $request->input('motif');

        $refus = DB::transaction(function () use ($reservation, $depuis, $vers, $action, $messageRefus, $motif, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! in_array($r->statut, $depuis, true) || $r->transporteur_id) {
                return $this->refus($messageRefus, $r);
            }

            $ancien = $r->statut;
            $r->statut = $vers;
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, $action, $request->user(), $ancien, $vers, $motif);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $reservation->refresh()->load('entrepot', 'producteur.utilisateur');
        $reservation->producteur?->utilisateur?->notify(new OperationModifieeNotification("reservation_{$action}", trim(sprintf(
            $messageProducteur, $reservation->numero_reservation, $reservation->entrepot?->nom_entrepot ?? '', $motif ?? ''
        )), ['reservation_id' => $reservation->id, 'numero_reservation' => $reservation->numero_reservation]));

        return response()->json(['message' => $messageSucces, 'reservation' => $reservation->fresh()]);
    }

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

    private function reservationDuTransporteur(Request $request, int $id): Reservation|JsonResponse
    {
        $utilisateur = $request->user();
        $reservation = Reservation::with('entrepot')->find($id);

        if (! $reservation) {
            return response()->json(['message' => 'Réservation introuvable.'], 404);
        }

        if ($utilisateur->role === 'administrateur') {
            return $reservation;
        }

        $transporteur = $utilisateur->transporteur;

        if (! $transporteur || $reservation->transporteur_id !== $transporteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette réservation.'], 403);
        }

        return $reservation;
    }

    private function capaciteDisponible(Entrepot $entrepot, ?int $excluant = null): float
    {
        // 'enregistree' est exclu : cette quantité est désormais comptée via le
        // stock créé par validerMarchandise(), pas via la réservation elle-même.
        $reserve = Reservation::where('entrepot_id', $entrepot->id)
            ->whereIn('statut', ['en_attente', 'confirmee', 'arrivee_entrepot'])
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
