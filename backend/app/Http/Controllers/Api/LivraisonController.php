<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLivraisonRequest;
use App\Http\Requests\UpdateStatutLivraisonRequest;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Transporteur;
use App\Models\Utilisateur;
use App\Notifications\LivraisonEnCoursNotification;
use App\Notifications\LivraisonTermineeNotification;
use App\Notifications\OperationModifieeNotification;
use App\Services\GestionStock;
use App\Services\ReceptionCommande;
use App\Support\CircuitLivraison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Circuit « entrepôt → acheteur » côté livraison (cahier des charges v2, §5) :
 * le gestionnaire organise la livraison d'une commande prête, le transporteur
 * accepte la mission, charge (sortie du stock), part, arrive, puis la remise
 * est confirmée par le code de réception de l'acheteur.
 */
class LivraisonController extends Controller
{
    public function __construct(
        private readonly GestionStock $gestionStock,
        private readonly ReceptionCommande $reception,
    ) {
    }

    // ── Gestionnaire d'entrepôt ───────────────────────────────────────────────

    /**
     * Organiser la livraison d'une commande prête : transporteur obligatoire,
     * départ et destination repris par défaut de l'entrepôt et de l'acheteur.
     */
    public function store(StoreLivraisonRequest $request): JsonResponse
    {
        $commande = Commande::with(['stock.entrepot', 'acheteur.utilisateur'])->findOrFail($request->commande_id);

        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        if ($refus = $this->transporteurIndisponible($request->transporteur_id)) {
            return $refus;
        }

        $livraison = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'prete') {
                return response()->json([
                    'message'       => 'Une livraison s\'organise quand la commande est prête (confirmée puis préparée).',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            if ($existante = Livraison::where('commande_id', $c->id)->whereNotIn('statut', ['annulee', 'retournee'])->first()) {
                return response()->json([
                    'message'          => 'Une livraison existe déjà pour cette commande.',
                    'numero_livraison' => $existante->numero_livraison,
                ], 422);
            }

            $entrepot = $commande->stock?->entrepot;
            $acheteur = $commande->acheteur;

            $livraison = Livraison::create([
                'commande_id'           => $c->id,
                'transporteur_id'       => (int) $request->transporteur_id,
                'origine'               => $request->origine ?: ($entrepot?->localisation ?: $entrepot?->nom_entrepot ?: 'Entrepôt'),
                'destination'           => $request->destination ?: ($c->livraison_adresse ?: $acheteur?->utilisateur?->adresse ?: 'Adresse de l\'acheteur'),
                // Lieu choisi par l'acheteur à la commande, sinon son lieu habituel
                'destination_latitude'  => $request->destination_latitude ?? $c->livraison_latitude ?? $acheteur?->latitude,
                'destination_longitude' => $request->destination_longitude ?? $c->livraison_longitude ?? $acheteur?->longitude,
                'date_depart'           => $request->date_depart,
                'statut'                => 'en_attente',
            ]);

            HistoriqueOperation::consigner('livraison', $livraison->id, 'creation', $request->user(), null, 'en_attente', null, [
                'commande_id' => $c->id, 'transporteur_id' => $livraison->transporteur_id,
            ]);

            return $livraison;
        });

        if ($livraison instanceof JsonResponse) {
            return $livraison;
        }

        $this->notifierTransporteur($livraison->transporteur_id, 'mission_assignee', sprintf(
            'Nouvelle livraison à accepter : %s (%s kg) vers %s — commande %s.',
            $commande->produit, $commande->quantite, $livraison->destination, $commande->numero_commande,
        ), $livraison);

        return response()->json([
            'message'   => 'Livraison organisée. Le transporteur doit accepter la mission.',
            'livraison' => $livraison->load([
                'commande:id,numero_commande,produit,quantite',
                'transporteur:id,utilisateur_id,type_vehicule,zone',
                'transporteur.utilisateur:id,nom,prenom,telephone',
            ]),
        ], 201);
    }

    /**
     * Changer (ou affecter) le transporteur tant que la marchandise n'est pas
     * chargée. Le nouveau transporteur doit accepter la mission.
     */
    public function changerTransporteur(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'transporteur_id' => ['required', 'integer', 'exists:transporteurs,id'],
            'motif'           => ['nullable', 'string', 'max:500'],
        ]);

        $livraison = Livraison::with('commande.stock')->find($id);

        if (! $livraison) {
            return response()->json(['message' => 'Livraison introuvable.'], 404);
        }

        if (! $livraison->commande?->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette livraison.'], 403);
        }

        if ($refus = $this->transporteurIndisponible($request->transporteur_id)) {
            return $refus;
        }

        $resultat = DB::transaction(function () use ($livraison, $request) {
            $l = Livraison::whereKey($livraison->id)->lockForUpdate()->first();

            if (! in_array($l->statut, CircuitLivraison::TRANSPORTEUR_MODIFIABLE, true)) {
                return response()->json([
                    'message'       => 'La marchandise est déjà chargée : le transporteur ne peut plus être changé (déclarez un incident).',
                    'statut_actuel' => $l->statut,
                ], 422);
            }

            if ($l->transporteur_id === (int) $request->transporteur_id) {
                return response()->json(['message' => 'Ce transporteur est déjà assigné à cette livraison.'], 422);
            }

            return ['ancien' => $this->affecter($l, (int) $request->transporteur_id, $request->user(), $request->input('motif'))];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $this->notifierAffectation($livraison->refresh(), $resultat['ancien']);

        return response()->json([
            'message'   => 'Transporteur de la livraison modifié. Il doit accepter la mission.',
            'livraison' => $livraison->fresh()->load('transporteur.utilisateur:id,nom,prenom,telephone'),
        ]);
    }

    // ── Transporteur ─────────────────────────────────────────────────────────

    /**
     * Missions de livraison du transporteur connecté (toutes pour l'administrateur),
     * avec le contact et l'adresse de l'acheteur, les actions possibles et l'étape attendue.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $query = Livraison::with([
            'commande:id,numero_commande,produit,quantite,acheteur_id,stock_id,statut',
            'commande.acheteur:id,utilisateur_id,latitude,longitude',
            'commande.acheteur.utilisateur:id,nom,prenom,telephone,adresse',
            'commande.stock:id,entrepot_id',
            'commande.stock.entrepot:id,nom_entrepot,localisation,latitude,longitude,utilisateur_id',
            'commande.stock.entrepot.utilisateur:id,telephone',
            'transporteur:id,utilisateur_id,type_vehicule',
            'transporteur.utilisateur:id,nom,prenom',
            'incidents' => fn ($q) => $q->ouverts(),
        ])->orderByDesc('id');

        if ($utilisateur->role !== 'administrateur') {
            $transporteur = $utilisateur->transporteur;

            if (! $transporteur) {
                return response()->json(['message' => 'Profil transporteur introuvable.'], 404);
            }

            $query->where('transporteur_id', $transporteur->id);
        }

        $livraisons = $query->get()->map(fn (Livraison $l) => array_merge($l->toArray(), [
            'actions'         => CircuitLivraison::actionsLivraison($l, $utilisateur, $l->incidents->contains(fn (Incident $i) => $i->estBloquant())),
            'prochaine_etape' => $l->commande ? CircuitLivraison::prochaineEtape($l->commande->setRelation('livraison', $l)) : null,
        ]));

        return response()->json(['livraisons' => $livraisons]);
    }

    /** Le transporteur accepte la mission proposée. */
    public function accepterMission(Request $request, int $id): JsonResponse
    {
        $refus = $this->sousVerrou($request, $id, function (Livraison $l) use ($request) {
            if ($l->statut !== 'en_attente' || $l->mission_acceptee_le !== null) {
                return $this->refus('Cette mission n\'est plus à accepter.', $l);
            }

            $l->statut              = 'prise_en_charge';
            $l->mission_acceptee_le = now();
            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, 'acceptation_mission', $request->user(), 'en_attente', 'prise_en_charge');

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $livraison = Livraison::with('commande.stock.entrepot.utilisateur')->find($id);
        $livraison->commande?->stock?->entrepot?->utilisateur?->notify(new OperationModifieeNotification('mission_acceptee', sprintf(
            '%s a accepté la livraison %s : il va venir charger la commande %s.',
            $this->nomComplet($request->user()), $livraison->numero_livraison, $livraison->commande?->numero_commande,
        ), $this->reference($livraison)));

        return response()->json(['message' => 'Mission acceptée. Rendez-vous à l\'entrepôt pour charger.', 'livraison' => $livraison->fresh()]);
    }

    /** Le transporteur refuse la mission (motif obligatoire) : elle revient au gestionnaire. */
    public function refuserMission(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez pourquoi vous refusez la mission.']);

        $refus = $this->sousVerrou($request, $id, function (Livraison $l) use ($request) {
            if ($l->statut !== 'en_attente' || $l->mission_acceptee_le !== null) {
                return $this->refus('Une mission acceptée ne peut plus être refusée : déclarez un incident.', $l);
            }

            $ancien             = $l->transporteur_id;
            $l->transporteur_id = null;
            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, 'refus_mission', $request->user(), 'en_attente', 'en_attente', $request->motif,
                ['ancien_transporteur_id' => $ancien]);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $livraison = Livraison::with('commande.stock.entrepot.utilisateur')->find($id);
        $livraison->commande?->stock?->entrepot?->utilisateur?->notify(new OperationModifieeNotification('mission_refusee', sprintf(
            '%s a refusé la livraison %s. Motif : %s. Affectez un autre transporteur.',
            $this->nomComplet($request->user()), $livraison->numero_livraison, $request->motif,
        ), $this->reference($livraison)));

        return response()->json(['message' => 'Mission refusée. L\'entrepôt va affecter un autre transporteur.', 'livraison' => $livraison->fresh()]);
    }

    /**
     * Étape de transport : chargee (sortie du stock, code de réception envoyé à
     * l'acheteur), en_cours (départ), arrivee (chez l'acheteur).
     */
    public function etape(Request $request, int $id): JsonResponse
    {
        $donnees = $request->validate(['etape' => ['required', Rule::in(array_keys(CircuitLivraison::ETAPES))]]);

        return $this->franchirEtape($request, $id, $donnees['etape']);
    }

    /** Remise à l'acheteur : le transporteur saisit le code que l'acheteur lui donne. */
    public function confirmerParCode(Request $request, int $id): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:4']], ['code.required' => 'Saisissez le code de réception de l\'acheteur.']);

        $refus = $this->sousVerrou($request, $id, function (Livraison $l) use ($request) {
            if ($l->statut !== 'arrivee') {
                return $this->refus('La remise se confirme une fois arrivé chez l\'acheteur.', $l);
            }

            $c = Commande::whereKey($l->commande_id)->lockForUpdate()->first();

            if (! $c || ! hash_equals((string) $c->code_reception, (string) $request->code)) {
                HistoriqueOperation::consigner('livraison', $l->id, 'code_errone', $request->user(), 'arrivee', 'arrivee');

                return response()->json(['message' => 'Code de réception incorrect. Demandez-le à nouveau à l\'acheteur.'], 422);
            }

            $this->reception->receptionner($c, $l, $request->user(), 'code');

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $livraison = Livraison::find($id);
        $this->notifierFin($livraison);

        return response()->json(['message' => 'Livraison remise à l\'acheteur. Mission terminée.', 'livraison' => $livraison->fresh()]);
    }

    /**
     * Ancienne route : « en_cours » franchit les étapes restantes jusqu'au départ
     * (acceptation, chargement) ; « livree » exige désormais le code de l'acheteur.
     */
    public function updateStatut(UpdateStatutLivraisonRequest $request, int $id): JsonResponse
    {
        if ($request->statut === 'livree') {
            return response()->json(['message' => 'La remise se confirme désormais avec le code de réception de l\'acheteur.'], 422);
        }

        $livraison = Livraison::find($id);

        if ($livraison?->statut === 'en_attente' && $livraison->mission_acceptee_le === null) {
            $reponse = $this->accepterMission($request, $id);

            if ($reponse->getStatusCode() !== 200) {
                return $reponse;
            }
        }

        foreach (['chargee', 'en_cours'] as $etape) {
            if (Livraison::find($id)?->statut === CircuitLivraison::ETAPES[$etape]) {
                $reponse = $this->franchirEtape($request, $id, $etape);

                if ($reponse->getStatusCode() !== 200) {
                    return $reponse;
                }
            }
        }

        return $reponse ?? $this->franchirEtape($request, $id, 'en_cours');
    }

    /** Ancienne route : un problème signalé devient un incident « autre ». */
    public function signalerProbleme(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'sujet_type'  => 'livraison',
            'sujet_id'    => $id,
            'type'        => 'autre',
            'description' => $request->input('motif') ?: 'Problème signalé par le transporteur.',
        ]);

        return app(IncidentController::class)->store($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function franchirEtape(Request $request, int $id, string $etape): JsonResponse
    {
        $refus = $this->sousVerrou($request, $id, function (Livraison $l) use ($request, $etape) {
            if ($l->statut !== CircuitLivraison::ETAPES[$etape]) {
                return $this->refus('Cette étape n\'est pas possible maintenant : les étapes se suivent dans l\'ordre.', $l);
            }

            if (Incident::bloque('livraison', $l->id)) {
                return $this->refus('Un incident est en cours : attendez la décision de l\'entrepôt.', $l);
            }

            $ancien    = $l->statut;
            $l->statut = $etape;
            $c         = Commande::whereKey($l->commande_id)->lockForUpdate()->first();

            if ($etape === 'chargee') {
                if ($c->etat_stock === 'reserve' && $c->stock_id) {
                    $this->gestionStock->sortirReserve($c->stock_id, (float) $c->quantite, $request->user(), 'livraison', $l->id);
                    $c->etat_stock = 'sorti';
                }
                $c->code_reception = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $c->statut         = 'en_livraison';
                $c->save();
                HistoriqueOperation::consigner('commande', $c->id, 'chargement', $request->user(), 'prete', 'en_livraison');
            } elseif ($etape === 'en_cours') {
                $l->parti_le    = now();
                $l->date_depart = $l->date_depart ?? now();
            } else {
                $l->arrive_le = now();
            }

            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, $etape, $request->user(), $ancien, $etape);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $livraison = Livraison::find($id);
        $this->notifierEtape($livraison, $etape);

        return response()->json([
            'message'   => match ($etape) {
                'chargee'  => 'Chargement confirmé. La marchandise est sortie du stock.',
                'en_cours' => 'Départ enregistré. L\'acheteur a été prévenu.',
                default    => 'Arrivée signalée. Demandez son code de réception à l\'acheteur.',
            },
            'livraison' => $livraison->fresh(),
        ]);
    }

    /**
     * Exécute $action sur la livraison verrouillée, après avoir vérifié qu'elle
     * est (toujours) celle du transporteur connecté. Renvoie un refus ou null.
     */
    private function sousVerrou(Request $request, int $id, callable $action): ?JsonResponse
    {
        if (! Livraison::whereKey($id)->exists()) {
            return response()->json(['message' => 'Livraison introuvable.'], 404);
        }

        return DB::transaction(function () use ($request, $id, $action) {
            $l = Livraison::whereKey($id)->lockForUpdate()->first();
            $u = $request->user();

            if ($u->role !== 'administrateur' && ($l->transporteur_id === null || $l->transporteur_id !== $u->transporteur?->id)) {
                return response()->json(['message' => 'Cette livraison ne vous est pas (ou plus) assignée.'], 403);
            }

            return $action($l);
        });
    }

    /** Affecte un transporteur (mission à accepter) ; renvoie l'ancien. À appeler sous verrou. */
    public function affecter(Livraison $l, int $transporteurId, ?Utilisateur $auteur, ?string $motif): ?int
    {
        $ancien                 = $l->transporteur_id;
        $ancienStatut           = $l->statut;
        $l->transporteur_id     = $transporteurId;
        $l->statut              = 'en_attente';
        $l->mission_acceptee_le = null;
        $l->save();
        HistoriqueOperation::consigner('livraison', $l->id, $ancien ? 'reassignation_transporteur' : 'assignation_transporteur',
            $auteur, $ancienStatut, 'en_attente', $motif, ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $transporteurId]);

        return $ancien;
    }

    public function notifierAffectation(Livraison $livraison, ?int $ancien): void
    {
        $livraison->loadMissing('commande');
        $this->notifierTransporteur($livraison->transporteur_id, 'mission_assignee', sprintf(
            'Nouvelle livraison à accepter : %s vers %s — commande %s.',
            $livraison->commande?->produit, $livraison->destination, $livraison->commande?->numero_commande,
        ), $livraison);

        if ($ancien && $ancien !== $livraison->transporteur_id) {
            $this->notifierTransporteur($ancien, 'mission_retiree', sprintf(
                'La livraison %s vous a été retirée par l\'entrepôt.', $livraison->numero_livraison,
            ), $livraison);
        }
    }

    private function transporteurIndisponible(mixed $transporteurId): ?JsonResponse
    {
        return Transporteur::with('utilisateur')->find($transporteurId)?->utilisateur?->statut === 'bloque'
            ? response()->json(['message' => 'Ce transporteur ne peut pas recevoir de mission (compte bloqué).'], 422)
            : null;
    }

    /** Acheteur prévenu à chaque étape ; le code de réception lui est donné au chargement. */
    private function notifierEtape(Livraison $livraison, string $etape): void
    {
        $livraison->load('commande.acheteur.utilisateur');
        $commande = $livraison->commande;
        $acheteur = $commande?->acheteur?->utilisateur;

        if ($etape === 'chargee') {
            $acheteur?->notify(new OperationModifieeNotification('commande_chargee', sprintf(
                'Votre commande %s est chargée. Votre code de réception : %s — donnez-le au transporteur à la livraison.',
                $commande->numero_commande, $commande->code_reception,
            ), ['commande_id' => $commande->id, ...$this->reference($livraison)]));
        } elseif ($etape === 'en_cours') {
            $position = PositionGps::where('sujet_type', 'livraison')->where('sujet_id', $livraison->id)->orderByDesc('timestamp')->first();
            $acheteur?->notify(new LivraisonEnCoursNotification($livraison, $position));
        } else {
            $acheteur?->notify(new OperationModifieeNotification('livraison_arrivee', sprintf(
                'Le transporteur est arrivé avec votre commande %s. Vérifiez la marchandise puis confirmez la réception, ou donnez-lui votre code.',
                $commande?->numero_commande,
            ), ['commande_id' => $commande?->id, ...$this->reference($livraison)]));
        }
    }

    /** Livraison terminée : acheteur, producteur du lot et gestionnaire prévenus. */
    public function notifierFin(Livraison $livraison): void
    {
        $livraison->load([
            'commande.acheteur.utilisateur',
            'commande.stock.production.producteur.utilisateur',
            'commande.stock.entrepot.utilisateur',
        ]);

        $livraison->commande?->acheteur?->utilisateur?->notify(new LivraisonTermineeNotification($livraison));
        $livraison->commande?->stock?->production?->producteur?->utilisateur?->notify(new LivraisonTermineeNotification($livraison));
        $livraison->commande?->stock?->entrepot?->utilisateur?->notify(new LivraisonTermineeNotification($livraison));
    }

    private function notifierTransporteur(?int $transporteurId, string $type, string $message, Livraison $livraison): void
    {
        if (! $transporteurId) {
            return;
        }

        Transporteur::with('utilisateur')->find($transporteurId)?->utilisateur?->notify(
            new OperationModifieeNotification($type, $message, $this->reference($livraison)),
        );
    }

    private function refus(string $message, Livraison $livraison): JsonResponse
    {
        return response()->json(['message' => $message, 'statut_actuel' => $livraison->statut], 422);
    }

    private function reference(Livraison $livraison): array
    {
        return ['livraison_id' => $livraison->id, 'numero_livraison' => $livraison->numero_livraison];
    }

    private function nomComplet(?Utilisateur $u): string
    {
        return trim(($u?->prenom ?? '').' '.($u?->nom ?? '')) ?: 'Le transporteur';
    }
}
