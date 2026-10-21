<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignerTransporteurReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\ValiderMarchandiseRequest;
use App\Models\Entrepot;
use App\Models\HistoriqueOperation;
use App\Models\Production;
use App\Models\Reservation;
use App\Models\Transporteur;
use App\Models\Utilisateur;
use App\Notifications\ArriveeEntrepotNotification;
use App\Notifications\MarchandiseEnregistreeNotification;
use App\Notifications\OperationModifieeNotification;
use App\Notifications\ReservationConfirmeeNotification;
use App\Services\GestionStock;
use App\Support\CircuitDepot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Circuit « récolte → entrepôt » (dépôt). Les étapes, les acteurs autorisés et
 * les actions affichées viennent de App\Support\CircuitDepot.
 */
class ReservationController extends Controller
{
    public function __construct(private readonly GestionStock $gestionStock)
    {
    }

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
     * Demander un dépôt en entrepôt pour un lot.
     *
     * Règles : la quantité ne dépasse ni la capacité disponible de l'entrepôt,
     * ni la partie du lot qui n'est pas déjà déposée ou en cours de dépôt
     * (un lot peut être réparti entre plusieurs entrepôts, décision Q8).
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $producteur = $request->user()->producteur;

        if (! $producteur) {
            return response()->json(['message' => 'Profil producteur introuvable.'], 404);
        }

        // Verrous sur le lot puis l'entrepôt : deux demandes simultanées ne peuvent
        // dépasser ni la quantité du lot ni la capacité disponible.
        $reservation = DB::transaction(function () use ($request, $producteur) {
            $production = Production::whereKey($request->production_id)->lockForUpdate()->firstOrFail();
            $restant    = $this->quantiteRestanteDuLot($production);

            if ($request->quantite_reservee > $restant + 1e-9) {
                return response()->json([
                    'message'           => 'Cette quantité dépasse ce qu\'il reste à déposer pour ce lot.',
                    'quantite_restante' => $restant,
                    'quantite_demandee' => $request->quantite_reservee,
                ], 422);
            }

            $entrepot   = Entrepot::whereKey($request->entrepot_id)->lockForUpdate()->firstOrFail();
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
                'production_id'     => $production->id,
                'produit'           => $request->produit ?: $production->type_culture,
                'quantite_reservee' => $request->quantite_reservee,
                'mode_transport'    => $request->mode_transport ?? CircuitDepot::MODE_TRANSPORTEUR,
                'date_debut'        => $request->date_debut,
                'date_fin'          => $request->date_fin,
                'date_reservation'  => now(),
                'statut'            => 'en_attente',
            ]);

            HistoriqueOperation::consigner('reservation', $reservation->id, 'creation', $request->user(), null, 'en_attente', null, [
                'production_id' => $reservation->production_id, 'quantite_reservee' => (float) $reservation->quantite_reservee,
                'mode_transport' => $reservation->mode_transport,
            ]);

            return $reservation;
        });

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $reservation->load('entrepot.utilisateur', 'production:id,code_tracabilite');
        $reservation->entrepot?->utilisateur?->notify(new OperationModifieeNotification('reservation_demandee', sprintf(
            'Nouvelle demande de dépôt %s : %s, %s kg (lot %s, %s).',
            $reservation->numero_reservation, $reservation->produit, $reservation->quantite_reservee,
            $reservation->production?->code_tracabilite ?? '—',
            $reservation->mode_transport === CircuitDepot::MODE_PRODUCTEUR ? 'le producteur livre lui-même' : 'transporteur à prévoir',
        ), $this->reference($reservation)));

        return response()->json([
            'message'     => 'Demande de dépôt envoyée. L\'entrepôt a été notifié.',
            'reservation' => $reservation->load('entrepot:id,nom_entrepot,localisation'),
        ], 201);
    }

    /**
     * Annulation par le producteur de sa propre demande, jusqu'au départ de la
     * marchandise. L'entrepôt et, le cas échéant, le transporteur sont prévenus.
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

            if (! in_array($r->statut, CircuitDepot::ANNULABLES, true)) {
                return $this->refus($r->statut === 'en_route' || $r->statut === 'arrivee_entrepot'
                    ? 'La marchandise est déjà partie : elle ne peut plus être annulée. Contactez l\'entrepôt.'
                    : 'Cette réservation ne peut plus être annulée.', $r);
            }

            $ancien = $r->statut;
            $r->statut = 'annulee';
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, 'annulation', $request->user(), $ancien, 'annulee', $request->input('motif'));

            return ['transporteur' => $r->transporteur_id];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $motif = $request->input('motif') ? ' Motif : '.$request->input('motif') : '';
        $reservation->entrepot?->utilisateur?->notify(new OperationModifieeNotification('reservation_annulee', sprintf(
            'Le producteur a annulé la réservation %s (%s, %s kg).%s',
            $reservation->numero_reservation, $reservation->produit, $reservation->quantite_reservee, $motif,
        ), $this->reference($reservation)));
        $this->notifierTransporteur($resultat['transporteur'], 'mission_annulee', sprintf(
            'La mission d\'acheminement %s est annulée : le producteur a annulé son dépôt.', $reservation->numero_reservation,
        ), $reservation);

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

        // Re-vérification de la capacité au moment de la confirmation, sous
        // verrou de l'entrepôt puis de la réservation : ni dépassement de capacité
        // par deux confirmations simultanées, ni double confirmation.
        $refus = DB::transaction(function () use ($reservation, $request) {
            $entrepot = Entrepot::whereKey($reservation->entrepot_id)->lockForUpdate()->first();
            $r        = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->statut !== 'en_attente') {
                return $this->refus('Seules les réservations en attente peuvent être confirmées.', $r);
            }

            // Capacité lue sur l'entrepôt verrouillé (une baisse de capacité simultanée est prise en compte).
            $disponible = $this->capaciteDisponible($entrepot, excluant: $r->id);

            if ($r->quantite_reservee > $disponible) {
                return response()->json([
                    'message'             => 'Capacité insuffisante pour confirmer cette réservation.',
                    'capacite_disponible' => $disponible,
                    'quantite_demandee'   => $r->quantite_reservee,
                ], 422);
            }

            $r->statut = 'confirmee';
            $r->save();
            HistoriqueOperation::consigner('reservation', $r->id, 'confirmation', $request->user(), 'en_attente', 'confirmee');

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $reservation->refresh();
        $reservation->producteur->utilisateur->notify(new ReservationConfirmeeNotification($reservation));

        return response()->json([
            'message'     => 'Réservation confirmée. Le producteur a été notifié.',
            'reservation' => $reservation->fresh()->load('entrepot:id,nom_entrepot'),
        ]);
    }

    /**
     * Affecter (ou réaffecter) un transporteur, avant son arrivée chez le
     * producteur. Le nouveau transporteur doit accepter la mission ; l'ancien
     * la perd et en est informé.
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

            if ($r->mode_transport === CircuitDepot::MODE_PRODUCTEUR) {
                return $this->refus('Le producteur livre lui-même cette marchandise : aucun transporteur n\'est à affecter.', $r);
            }

            if (! in_array($r->statut, CircuitDepot::TRANSPORTEUR_MODIFIABLE, true)) {
                return $this->refus('Le transporteur ne peut plus être changé : il est déjà chez le producteur ou la marchandise est partie.', $r);
            }

            $ancien = $r->transporteur_id;

            if ($ancien === $transporteur->id) {
                return response()->json(['message' => 'Ce transporteur est déjà assigné à cette réservation.'], 422);
            }

            $ancienStatut           = $r->statut;
            $r->transporteur_id     = $transporteur->id;
            $r->mission_acceptee_le = null;
            $r->statut              = 'confirmee';
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, $ancien ? 'reassignation_transporteur' : 'assignation_transporteur',
                $request->user(), $ancienStatut, $r->statut, $request->input('motif'),
                ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $transporteur->id]);

            return ['ancien' => $ancien];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $reservation->refresh()->load('entrepot', 'producteur.utilisateur');
        $this->notifierTransporteur($transporteur->id, 'mission_assignee', sprintf(
            'Nouvelle mission à accepter : acheminer %s (%s kg) de chez %s vers l\'entrepôt %s — réservation %s.',
            $reservation->produit, $reservation->quantite_reservee,
            trim(($reservation->producteur?->utilisateur?->prenom ?? '').' '.($reservation->producteur?->utilisateur?->nom ?? '')),
            $reservation->entrepot?->nom_entrepot ?? '', $reservation->numero_reservation,
        ), $reservation);
        $this->notifierTransporteur($resultat['ancien'], 'mission_retiree', sprintf(
            'La mission d\'acheminement %s vous a été retirée par l\'entrepôt.', $reservation->numero_reservation,
        ), $reservation);

        return response()->json([
            'message'     => $resultat['ancien'] ? 'Mission réassignée au nouveau transporteur.' : 'Transporteur assigné à la réservation.',
            'reservation' => $reservation->fresh()->load('transporteur.utilisateur:id,nom,prenom,telephone'),
        ]);
    }

    /** Retirer le transporteur avant son arrivée chez le producteur. */
    public function retirerTransporteur(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $resultat = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! in_array($r->statut, CircuitDepot::TRANSPORTEUR_MODIFIABLE, true) || ! $r->transporteur_id) {
                return $this->refus('Aucune mission à retirer : aucun transporteur n\'est assigné, ou il est déjà chez le producteur.', $r);
            }

            $ancien                 = $r->transporteur_id;
            $ancienStatut           = $r->statut;
            $r->transporteur_id     = null;
            $r->mission_acceptee_le = null;
            $r->statut              = 'confirmee';
            $r->save();

            HistoriqueOperation::consigner('reservation', $r->id, 'retrait_transporteur', $request->user(),
                $ancienStatut, $r->statut, $request->input('motif'), ['ancien_transporteur_id' => $ancien]);

            return ['ancien' => $ancien];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $this->notifierTransporteur($resultat['ancien'], 'mission_retiree', sprintf(
            'La mission d\'acheminement %s vous a été retirée par l\'entrepôt.', $reservation->numero_reservation,
        ), $reservation->refresh());

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
     * Réception d'une marchandise arrivée à l'entrepôt (étapes 9a et 9b).
     *
     * Le gestionnaire contrôle le code du lot, la quantité reçue et l'état. Un
     * écart avec la quantité prévue exige un motif ; une quantité supérieure
     * doit tenir dans la capacité restante. Crée l'entrée de stock (mouvement
     * « entree ») liée au lot, puis notifie le producteur.
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

        $reservation->loadMissing('production:id,code_tracabilite');

        if (strcasecmp(trim((string) $request->code_lot), (string) $reservation->production?->code_tracabilite) !== 0) {
            return response()->json([
                'message' => 'Le code du lot ne correspond pas à cette réservation. Vérifiez l\'étiquette de la marchandise.',
                'errors'  => ['code_lot' => ['Code de lot différent de celui attendu.']],
            ], 422);
        }

        $recue  = (float) $request->quantite_reelle;
        $prevue = (float) $reservation->quantite_reservee;
        $ecart  = abs($recue - $prevue) > 1e-9;

        if ($ecart && trim((string) $request->motif) === '') {
            return response()->json([
                'message' => 'La quantité reçue diffère de la quantité prévue : indiquez la raison de l\'écart.',
                'errors'  => ['motif' => ['Motif de l\'écart obligatoire.']],
            ], 422);
        }

        // Verrou + revérification : deux validations simultanées (double clic,
        // nouvel essai) ne peuvent pas créer deux lignes de stock pour le même lot.
        $stock = DB::transaction(function () use ($reservation, $request, $recue, $prevue, $ecart) {
            $entrepot    = Entrepot::whereKey($reservation->entrepot_id)->lockForUpdate()->first();
            $verrouillee = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($verrouillee->statut !== 'arrivee_entrepot') {
                return $this->refus('Cette marchandise a déjà été enregistrée ou n\'est plus à l\'entrepôt.', $verrouillee);
            }

            // La place prévue est déjà comptée : seul le surplus doit tenir dans la capacité restante.
            if ($recue > $prevue && $recue - $prevue > $this->capaciteDisponible($entrepot) + 1e-9) {
                return response()->json([
                    'message'             => 'L\'entrepôt n\'a pas la place pour cette quantité supplémentaire.',
                    'capacite_disponible' => $this->capaciteDisponible($entrepot),
                    'quantite_supplementaire' => $recue - $prevue,
                ], 422);
            }

            // Seule entrée en stock possible : elle laisse un mouvement « entree ».
            $stock = $this->gestionStock->entrer([
                'entrepot_id'   => $reservation->entrepot_id,
                'production_id' => $reservation->production_id,
                'produit'       => $reservation->produit,
                'quantite'      => $recue,
                'date_entree'   => now()->toDateString(),
                'observation'   => $request->observation,
            ], $request->user(), 'reservation', $verrouillee->id);

            $verrouillee->statut           = 'enregistree';
            $verrouillee->quantite_recue   = $recue;
            $verrouillee->etat_marchandise = $request->etat_marchandise;
            $verrouillee->save();
            HistoriqueOperation::consigner('reservation', $verrouillee->id, $ecart ? 'reception_partielle' : 'reception', $request->user(),
                'arrivee_entrepot', 'enregistree', $request->motif ?: $request->observation, [
                    'quantite_reservee' => $prevue, 'quantite_recue' => $recue,
                    'etat_marchandise' => $request->etat_marchandise, 'stock_id' => $stock->id,
                ]);

            // La récolte liée est désormais physiquement en entrepôt ; sa quantité
            // réelle est la somme reçue dans tous les entrepôts.
            if ($production = Production::whereKey($reservation->production_id)->lockForUpdate()->first()) {
                $production->update([
                    'statut'          => 'disponible',
                    'quantite_reelle' => (float) Reservation::where('production_id', $production->id)
                        ->where('statut', 'enregistree')->sum('quantite_recue'),
                ]);
            }

            return $stock;
        });

        if ($stock instanceof JsonResponse) {
            return $stock;
        }

        $reservation->refresh()->load('producteur.utilisateur');
        $reservation->producteur?->utilisateur?->notify(new MarchandiseEnregistreeNotification($stock->load('entrepot')));

        if ($ecart) {
            $reservation->producteur?->utilisateur?->notify(new OperationModifieeNotification('reception_partielle', sprintf(
                'Réception %s : %s kg reçus sur %s kg prévus. Motif : %s',
                $reservation->numero_reservation, $recue, $prevue, $request->motif,
            ), $this->reference($reservation)));
        }

        return response()->json([
            'message'     => $ecart
                ? 'Réception enregistrée avec un écart. Le producteur a été notifié.'
                : 'Marchandise enregistrée en stock. Le producteur a été notifié.',
            'stock'       => $stock->load('production:id,code_tracabilite,type_culture'),
            'reservation' => $reservation->fresh(),
        ], 201);
    }

    /**
     * Réception refusée (étape 9c) : aucun stock n'est créé, la place est libérée
     * et le lot peut faire l'objet d'une nouvelle demande. Motif obligatoire.
     */
    public function refuserReception(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez pourquoi la marchandise est refusée.']);
        $reservation = $this->reservationDeLEntrepot($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $resultat = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->statut !== 'arrivee_entrepot') {
                return $this->refus('Seule une marchandise arrivée à l\'entrepôt peut être refusée.', $r);
            }

            $r->statut = 'reception_refusee';
            $r->save();
            HistoriqueOperation::consigner('reservation', $r->id, 'refus_reception', $request->user(), 'arrivee_entrepot', 'reception_refusee', $request->motif);

            return ['transporteur' => $r->mode_transport === CircuitDepot::MODE_TRANSPORTEUR ? $r->transporteur_id : null];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $reservation->refresh()->load('producteur.utilisateur', 'entrepot');
        $message = sprintf(
            'La marchandise de la réservation %s a été refusée à l\'entrepôt %s. Motif : %s. Elle doit être reprise.',
            $reservation->numero_reservation, $reservation->entrepot?->nom_entrepot ?? '', $request->motif,
        );
        $reservation->producteur?->utilisateur?->notify(new OperationModifieeNotification('reception_refusee', $message, $this->reference($reservation)));
        $this->notifierTransporteur($resultat['transporteur'], 'reception_refusee', $message, $reservation);

        return response()->json(['message' => 'Réception refusée. Le producteur a été notifié.', 'reservation' => $reservation->fresh()]);
    }

    // ── Transporteur et producteur qui livre lui-même ─────────────────────────

    /** Le transporteur accepte la mission qui lui est proposée (étape 5). */
    public function accepterMission(Request $request, int $id): JsonResponse
    {
        $reservation = $this->reservationDuTransporteur($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $refus = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->transporteur_id !== $request->user()->transporteur?->id) {
                return response()->json(['message' => 'Cette mission ne vous est plus assignée.'], 403);
            }

            if ($r->statut !== 'confirmee' || $r->mission_acceptee_le !== null) {
                return $this->refus('Cette mission n\'est plus à accepter.', $r);
            }

            $r->statut              = 'prise_en_charge';
            $r->mission_acceptee_le = now();
            $r->save();
            HistoriqueOperation::consigner('reservation', $r->id, 'acceptation_mission', $request->user(), 'confirmee', 'prise_en_charge');

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $reservation->refresh()->load('entrepot.utilisateur', 'producteur.utilisateur', 'transporteur.utilisateur');
        $nom     = $this->nomComplet($reservation->transporteur?->utilisateur);
        $message = sprintf('Le transporteur %s a accepté la mission %s : il va venir chercher la marchandise.', $nom, $reservation->numero_reservation);
        $reservation->entrepot?->utilisateur?->notify(new OperationModifieeNotification('mission_acceptee', $message, $this->reference($reservation)));
        $reservation->producteur?->utilisateur?->notify(new OperationModifieeNotification('mission_acceptee', $message, $this->reference($reservation)));

        return response()->json(['message' => 'Mission acceptée. L\'entrepôt et le producteur ont été prévenus.', 'reservation' => $reservation->fresh()]);
    }

    /** Le transporteur refuse la mission (motif obligatoire) : elle revient au gestionnaire. */
    public function refuserMission(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez pourquoi vous refusez la mission.']);
        $reservation = $this->reservationDuTransporteur($request, $id);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        $refus = DB::transaction(function () use ($reservation, $request) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if ($r->transporteur_id !== $request->user()->transporteur?->id) {
                return response()->json(['message' => 'Cette mission ne vous est plus assignée.'], 403);
            }

            if ($r->statut !== 'confirmee' || $r->mission_acceptee_le !== null) {
                return $this->refus('Une mission acceptée ne peut plus être refusée : prévenez l\'entrepôt.', $r);
            }

            $ancien             = $r->transporteur_id;
            $r->transporteur_id = null;
            $r->save();
            HistoriqueOperation::consigner('reservation', $r->id, 'refus_mission', $request->user(), 'confirmee', 'confirmee', $request->motif,
                ['ancien_transporteur_id' => $ancien]);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $reservation->refresh()->load('entrepot.utilisateur');
        $reservation->entrepot?->utilisateur?->notify(new OperationModifieeNotification('mission_refusee', sprintf(
            '%s a refusé la mission %s. Motif : %s. Affectez un autre transporteur.',
            $this->nomComplet($request->user()), $reservation->numero_reservation, $request->motif,
        ), $this->reference($reservation)));

        return response()->json(['message' => 'Mission refusée. L\'entrepôt va affecter un autre transporteur.', 'reservation' => $reservation->fresh()]);
    }

    /**
     * Étape de transport (6 à 8) franchie par la personne qui conduit : le
     * transporteur qui a accepté la mission, ou le producteur qui livre lui-même.
     */
    public function etape(Request $request, int $id): JsonResponse
    {
        $donnees = $request->validate(['etape' => ['required', Rule::in(array_keys(CircuitDepot::ETAPES))]]);

        return $this->franchirEtape($request, $id, $donnees['etape']);
    }

    /** Ancienne route du transporteur : équivaut à l'étape « arrivée à l'entrepôt ». */
    public function marquerArrivee(Request $request, int $id): JsonResponse
    {
        return $this->franchirEtape($request, $id, 'arrivee_entrepot');
    }

    private function franchirEtape(Request $request, int $id, string $etape): JsonResponse
    {
        $reservation = Reservation::find($id);

        if (! $reservation) {
            return response()->json(['message' => 'Réservation introuvable.'], 404);
        }

        if (! CircuitDepot::estConducteur($reservation, $request->user())) {
            return response()->json(['message' => 'Cette étape est réservée à la personne qui transporte la marchandise.'], 403);
        }

        $refus = DB::transaction(function () use ($reservation, $request, $etape) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            // Revérifié sous verrou : la mission a pu être retirée pendant la requête.
            if (! CircuitDepot::estConducteur($r, $request->user())) {
                return response()->json(['message' => 'Cette mission ne vous est plus assignée.'], 403);
            }

            $depuis = CircuitDepot::depuis($etape, $r->mode_transport) ?? [];

            if (! in_array($r->statut, $depuis, true)) {
                return $this->refus('Cette étape n\'est pas possible maintenant : les étapes se suivent dans l\'ordre.', $r);
            }

            $ancien    = $r->statut;
            $r->statut = $etape;
            $r->save();
            HistoriqueOperation::consigner('reservation', $r->id, $etape, $request->user(), $ancien, $etape);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $reservation->refresh()->load('entrepot.utilisateur', 'producteur.utilisateur');
        $this->notifierEtape($reservation, $etape);

        return response()->json([
            'message'     => match ($etape) {
                'au_point_collecte' => 'Arrivée chez le producteur enregistrée.',
                'chargee'           => 'Chargement confirmé.',
                'en_route'          => 'Départ enregistré. L\'entrepôt a été prévenu.',
                default             => 'Arrivée signalée. Le gestionnaire a été notifié.',
            },
            'reservation' => $reservation->fresh(),
        ]);
    }

    // ── Commun ─────────────────────────────────────────────────────────────────

    /**
     * Liste des réservations : le producteur voit les siennes, le gestionnaire
     * celles de son entrepôt, le transporteur ses missions, l'administrateur tout.
     * Chaque réservation porte ses actions possibles et l'étape attendue.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $query = Reservation::with([
            'entrepot:id,nom_entrepot,localisation,latitude,longitude,utilisateur_id',
            'entrepot.utilisateur:id,telephone',
            'production:id,code_tracabilite,type_culture',
            'producteur:id,utilisateur_id,region,latitude,longitude',
            'producteur.utilisateur:id,nom,prenom,telephone,adresse',
            'transporteur:id,utilisateur_id,type_vehicule',
            'transporteur.utilisateur:id,nom,prenom,telephone',
        ])->orderByDesc('date_reservation');

        // Historique : utile au producteur, au gestionnaire et à l'administrateur ;
        // non exposé au transporteur (il cite d'autres transporteurs).
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

        $reservations = $query->get()->map(fn (Reservation $r) => array_merge($r->toArray(), [
            'actions'         => CircuitDepot::actions($r, $utilisateur),
            'prochaine_etape' => CircuitDepot::prochaineEtape($r),
        ]));

        return response()->json(['reservations' => $reservations]);
    }

    // ── Helpers privés ─────────────────────────────────────────────────────────

    private function refus(string $message, Reservation $reservation): JsonResponse
    {
        return response()->json(['message' => $message, 'statut_actuel' => $reservation->statut], 422);
    }

    private function reference(Reservation $reservation): array
    {
        return ['reservation_id' => $reservation->id, 'numero_reservation' => $reservation->numero_reservation];
    }

    private function nomComplet(?Utilisateur $u): string
    {
        return trim(($u?->prenom ?? '').' '.($u?->nom ?? '')) ?: 'Le transporteur';
    }

    private function notifierTransporteur(?int $transporteurId, string $type, string $message, Reservation $reservation): void
    {
        if (! $transporteurId) {
            return;
        }

        Transporteur::with('utilisateur')->find($transporteurId)?->utilisateur
            ?->notify(new OperationModifieeNotification($type, $message, $this->reference($reservation)));
    }

    /** Qui est prévenu à chaque étape de transport (le conducteur n'est pas notifié de sa propre action). */
    private function notifierEtape(Reservation $r, string $etape): void
    {
        $parTransporteur = $r->mode_transport === CircuitDepot::MODE_TRANSPORTEUR;
        $producteur      = $parTransporteur ? $r->producteur?->utilisateur : null;
        $gestionnaire    = $r->entrepot?->utilisateur;

        match ($etape) {
            'au_point_collecte' => $producteur?->notify(new OperationModifieeNotification('transporteur_arrive',
                sprintf('Le transporteur est arrivé chez vous pour la réservation %s.', $r->numero_reservation), $this->reference($r))),
            'en_route' => collect([$gestionnaire, $producteur])->filter()->each->notify(new OperationModifieeNotification('depot_en_route',
                sprintf('La marchandise de la réservation %s (%s, %s kg) est en route vers l\'entrepôt.', $r->numero_reservation, $r->produit, $r->quantite_reservee),
                $this->reference($r))),
            'arrivee_entrepot' => (function () use ($gestionnaire, $producteur, $r) {
                $gestionnaire?->notify(new ArriveeEntrepotNotification($r));
                $producteur?->notify(new OperationModifieeNotification('depot_arrive',
                    sprintf('La marchandise de la réservation %s est arrivée à l\'entrepôt : réception en cours.', $r->numero_reservation), $this->reference($r)));
            })(),
            default => null,
        };
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
        )), $this->reference($reservation)));

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
        $transporteur = $request->user()->transporteur;
        $reservation  = Reservation::with('entrepot')->find($id);

        if (! $reservation) {
            return response()->json(['message' => 'Réservation introuvable.'], 404);
        }

        if (! $transporteur || $reservation->transporteur_id !== $transporteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette réservation.'], 403);
        }

        return $reservation;
    }

    /** Partie du lot qui n'est ni déposée ni en cours de dépôt (quantité déclarée par le producteur). */
    private function quantiteRestanteDuLot(Production $production): float
    {
        $engagee = Reservation::where('production_id', $production->id)
            ->whereIn('statut', CircuitDepot::STATUTS_ACTIFS)
            ->sum('quantite_reservee');
        $recue = Reservation::where('production_id', $production->id)
            ->where('statut', 'enregistree')
            ->sum('quantite_recue');

        return max(0.0, (float) $production->quantite_estimee - $engagee - $recue);
    }

    private function capaciteDisponible(Entrepot $entrepot, ?int $excluant = null): float
    {
        // 'enregistree' est exclu : cette quantité est désormais comptée via le
        // stock créé par validerMarchandise(), pas via la réservation elle-même.
        $reserve = Reservation::where('entrepot_id', $entrepot->id)
            ->whereIn('statut', CircuitDepot::STATUTS_ACTIFS)
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
