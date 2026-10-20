<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLivraisonRequest;
use App\Http\Requests\UpdateStatutLivraisonRequest;
use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Models\Transporteur;
use App\Notifications\LivraisonEnCoursNotification;
use App\Notifications\LivraisonTermineeNotification;
use App\Notifications\OperationModifieeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LivraisonController extends Controller
{
    // ── Gestionnaire d'entrepôt ───────────────────────────────────────────────

    /**
     * Créer une livraison pour une commande confirmée et assigner un transporteur.
     *
     * Règle métier 3 : la commande doit être au statut confirmee.
     * Option B retenue : la livraison est un acte délibéré du gestionnaire,
     * séparé de la confirmation de commande.
     */
    public function store(StoreLivraisonRequest $request): JsonResponse
    {
        $commande = Commande::with(['stock', 'livraison'])->findOrFail($request->commande_id);

        // Contrôle de périmètre : seul le gestionnaire de l'entrepôt du stock (ou l'admin)
        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        // Règle métier 3 — vérification du statut de la commande
        if ($commande->statut !== 'confirmee') {
            return response()->json([
                'message'       => 'Une livraison ne peut être créée que pour une commande confirmée.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        // Une commande ne peut avoir qu'une seule livraison active
        if ($commande->livraison) {
            return response()->json([
                'message'          => 'Une livraison existe déjà pour cette commande.',
                'numero_livraison' => $commande->livraison->numero_livraison,
            ], 422);
        }

        if ($request->transporteur_id && Transporteur::with('utilisateur')->find($request->transporteur_id)?->utilisateur?->statut === 'bloque') {
            return response()->json(['message' => 'Ce transporteur ne peut pas recevoir de mission (compte bloqué).'], 422);
        }

        // Verrou sur la commande + revérification : deux créations simultanées
        // (double clic, nouvel essai) ne produisent jamais deux livraisons.
        $livraison = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'confirmee') {
                return response()->json([
                    'message'       => 'Une livraison ne peut être créée que pour une commande confirmée.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            if ($existante = Livraison::where('commande_id', $c->id)->first()) {
                return response()->json([
                    'message'          => 'Une livraison existe déjà pour cette commande.',
                    'numero_livraison' => $existante->numero_livraison,
                ], 422);
            }

            return Livraison::create([
                'commande_id'            => $c->id,
                'transporteur_id'        => $request->transporteur_id,
                'origine'                => $request->origine,
                'destination'            => $request->destination,
                'destination_latitude'   => $request->destination_latitude,
                'destination_longitude'  => $request->destination_longitude,
                'date_depart'            => $request->date_depart,
                'statut'                 => 'en_attente',
            ]);
        });

        if ($livraison instanceof JsonResponse) {
            return $livraison;
        }

        $this->notifierTransporteur($livraison->transporteur_id, 'mission_assignee', sprintf(
            'Nouvelle livraison : %s vers %s — commande %s.', $commande->produit, $livraison->destination, $commande->numero_commande,
        ), $livraison);

        return response()->json([
            'message'   => 'Livraison créée avec succès.',
            'livraison' => $livraison->load([
                'commande:id,numero_commande,produit,quantite',
                'transporteur:id,utilisateur_id,type_vehicule,zone',
                'transporteur.utilisateur:id,nom,prenom,telephone',
            ]),
        ], 201);
    }

    /**
     * Corriger le transporteur d'une livraison qui n'a pas encore démarré.
     * Une livraison en cours, livrée ou en problème n'est plus réassignable.
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

        if (Transporteur::with('utilisateur')->find($request->transporteur_id)?->utilisateur?->statut === 'bloque') {
            return response()->json(['message' => 'Ce transporteur ne peut pas recevoir de mission (compte bloqué).'], 422);
        }

        $resultat = DB::transaction(function () use ($livraison, $request) {
            $l = Livraison::whereKey($livraison->id)->lockForUpdate()->first();

            if ($l->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'La livraison a déjà démarré ou est terminée : le transporteur ne peut plus être changé.',
                    'statut_actuel' => $l->statut,
                ], 422);
            }

            $ancien = $l->transporteur_id;

            if ($ancien === (int) $request->transporteur_id) {
                return response()->json(['message' => 'Ce transporteur est déjà assigné à cette livraison.'], 422);
            }

            $l->transporteur_id = (int) $request->transporteur_id;
            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, $ancien ? 'reassignation_transporteur' : 'assignation_transporteur',
                $request->user(), $l->statut, $l->statut, $request->input('motif'),
                ['ancien_transporteur_id' => $ancien, 'nouveau_transporteur_id' => $l->transporteur_id]);

            return ['ancien' => $ancien];
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $livraison->refresh();
        $this->notifierTransporteur($livraison->transporteur_id, 'mission_assignee', sprintf(
            'Nouvelle livraison : %s vers %s — commande %s.', $livraison->commande->produit, $livraison->destination, $livraison->commande->numero_commande,
        ), $livraison);
        $this->notifierTransporteur($resultat['ancien'], 'mission_retiree', sprintf(
            'La livraison %s vous a été retirée par l\'entrepôt.', $livraison->numero_livraison,
        ), $livraison);

        return response()->json([
            'message'   => 'Transporteur de la livraison modifié.',
            'livraison' => $livraison->fresh()->load('transporteur.utilisateur:id,nom,prenom,telephone'),
        ]);
    }

    private function notifierTransporteur(?int $transporteurId, string $type, string $message, Livraison $livraison): void
    {
        if (! $transporteurId) {
            return;
        }

        Transporteur::with('utilisateur')->find($transporteurId)?->utilisateur?->notify(new OperationModifieeNotification(
            $type, $message, ['livraison_id' => $livraison->id, 'numero_livraison' => $livraison->numero_livraison],
        ));
    }

    // ── Transporteur ─────────────────────────────────────────────────────────

    /**
     * Liste des missions de livraison assignées au transporteur connecté.
     * L'administrateur voit toutes les livraisons.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->role === 'administrateur') {
            $livraisons = Livraison::with([
                'commande:id,numero_commande,produit,quantite,acheteur_id',
                'commande.acheteur:id,utilisateur_id',
                'commande.acheteur.utilisateur:id,nom,prenom',
                'transporteur:id,utilisateur_id,type_vehicule',
                'transporteur.utilisateur:id,nom,prenom',
            ])->orderByDesc('date_depart')->get();
        } else {
            $transporteur = $utilisateur->transporteur;

            if (! $transporteur) {
                return response()->json(['message' => 'Profil transporteur introuvable.'], 404);
            }

            $livraisons = Livraison::where('transporteur_id', $transporteur->id)
                ->with([
                    'commande:id,numero_commande,produit,quantite',
                ])
                ->orderByDesc('date_depart')
                ->get();
        }

        return response()->json(['livraisons' => $livraisons]);
    }

    /**
     * Mettre à jour le statut d'une livraison.
     * Transitions valides : en_attente → en_cours → livree.
     * Le statut probleme est géré via signalerProbleme().
     */
    public function updateStatut(UpdateStatutLivraisonRequest $request, int $id): JsonResponse
    {
        $livraison = $this->livraisonDuTransporteur($request, $id);

        if ($livraison instanceof JsonResponse) {
            return $livraison;
        }

        // Transitions de statut autorisées
        $transitions = [
            'en_attente' => ['en_cours'],
            'en_cours'   => ['livree'],
        ];

        $nouveauStatut = $request->statut;

        // Verrou + revérification : ni double transition, ni mise à jour par un
        // transporteur à qui la livraison vient d'être retirée.
        $resultat = DB::transaction(function () use ($livraison, $request, $transitions, $nouveauStatut) {
            $l = $this->verrouillerPourLeTransporteur($request, $livraison);

            if ($l instanceof JsonResponse) {
                return $l;
            }

            $transitionsOk = $transitions[$l->statut] ?? [];

            if (! in_array($nouveauStatut, $transitionsOk)) {
                return response()->json([
                    'message'             => 'Transition de statut non autorisée.',
                    'statut_actuel'       => $l->statut,
                    'transitions_valides' => $transitionsOk,
                ], 422);
            }

            $l->statut = $nouveauStatut;

            // Horodatage automatique à la livraison
            if ($nouveauStatut === 'livree') {
                $l->date_livraison = now();
            }

            $l->save();

            return $l;
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $livraison = $resultat;

        $this->notifierChangementStatut($livraison, $nouveauStatut);

        return response()->json([
            'message'   => 'Statut mis à jour.',
            'livraison' => $livraison->fresh(),
        ]);
    }

    /**
     * Notifie l'acheteur (en_cours, avec la dernière position GPS si disponible)
     * et, à la livraison terminée, l'acheteur ainsi que le producteur du lot.
     */
    private function notifierChangementStatut(Livraison $livraison, string $nouveauStatut): void
    {
        $livraison->loadMissing([
            'commande.acheteur.utilisateur',
            'commande.stock.production.producteur.utilisateur',
        ]);

        $acheteurUtilisateur   = $livraison->commande?->acheteur?->utilisateur;
        $producteurUtilisateur = $livraison->commande?->stock?->production?->producteur?->utilisateur;

        if ($nouveauStatut === 'en_cours') {
            $position = PositionGps::where('transporteur_id', $livraison->transporteur_id)
                ->orderByDesc('timestamp')
                ->first();

            $acheteurUtilisateur?->notify(new LivraisonEnCoursNotification($livraison, $position));
        }

        if ($nouveauStatut === 'livree') {
            $acheteurUtilisateur?->notify(new LivraisonTermineeNotification($livraison));
            $producteurUtilisateur?->notify(new LivraisonTermineeNotification($livraison));
        }
    }

    /**
     * Signaler un problème sur une livraison.
     *
     * Règle métier 4 : la commande associée repasse en alerte visuelle côté
     * acheteur (flag alerte_livraison dans GET /api/commandes/historique).
     * Aucun changement automatique du statut de la commande.
     */
    public function signalerProbleme(Request $request, int $id): JsonResponse
    {
        $livraison = $this->livraisonDuTransporteur($request, $id);

        if ($livraison instanceof JsonResponse) {
            return $livraison;
        }

        $resultat = DB::transaction(function () use ($livraison, $request) {
            $l = $this->verrouillerPourLeTransporteur($request, $livraison);

            if ($l instanceof JsonResponse) {
                return $l;
            }

            if ($l->statut === 'livree') {
                return response()->json([
                    'message' => 'Impossible de signaler un problème sur une livraison déjà livrée.',
                ], 422);
            }

            if ($l->statut === 'probleme') {
                return response()->json([
                    'message' => 'Un problème est déjà signalé sur cette livraison.',
                ], 422);
            }

            $l->statut = 'probleme';
            $l->save();

            return $l;
        });

        if ($resultat instanceof JsonResponse) {
            return $resultat;
        }

        $livraison = $resultat;

        // Règle métier 4 — l'alerte visuelle est portée par le flag alerte_livraison
        // dans GET /api/commandes/historique. Aucun changement de statut commande ici.

        return response()->json([
            'message'   => 'Problème signalé. L\'acheteur sera alerté dans son suivi de commande.',
            'livraison' => $livraison->fresh()->load('commande:id,numero_commande,statut'),
        ]);
    }

    // ── Helper privé ──────────────────────────────────────────────────────────

    /** Relit la livraison sous verrou et revérifie qu'elle appartient toujours au transporteur connecté. */
    private function verrouillerPourLeTransporteur(Request $request, Livraison $livraison): Livraison|JsonResponse
    {
        $l = Livraison::whereKey($livraison->id)->lockForUpdate()->first();

        if ($request->user()->role !== 'administrateur' && $l->transporteur_id !== $request->user()->transporteur?->id) {
            return response()->json(['message' => 'Cette livraison ne vous est plus assignée.'], 403);
        }

        return $l;
    }

    /**
     * Récupère une livraison appartenant au transporteur connecté.
     * L'admin peut accéder à n'importe quelle livraison.
     */
    private function livraisonDuTransporteur(Request $request, int $id): Livraison|JsonResponse
    {
        $utilisateur = $request->user();
        $livraison   = Livraison::find($id);

        if (! $livraison) {
            return response()->json(['message' => 'Livraison introuvable.'], 404);
        }

        if ($utilisateur->role === 'administrateur') {
            return $livraison;
        }

        $transporteur = $utilisateur->transporteur;

        if (! $transporteur || $livraison->transporteur_id !== $transporteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette livraison.'], 403);
        }

        return $livraison;
    }
}
