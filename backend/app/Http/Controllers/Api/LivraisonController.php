<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLivraisonRequest;
use App\Http\Requests\UpdateStatutLivraisonRequest;
use App\Models\Commande;
use App\Models\Livraison;
use App\Models\PositionGps;
use App\Notifications\LivraisonEnCoursNotification;
use App\Notifications\LivraisonTermineeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $commande = Commande::with('livraison')->findOrFail($request->commande_id);

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

        $livraison = Livraison::create([
            'commande_id'     => $commande->id,
            'transporteur_id' => $request->transporteur_id,
            'origine'         => $request->origine,
            'destination'     => $request->destination,
            'date_depart'     => $request->date_depart,
            'statut'          => 'en_attente',
        ]);

        return response()->json([
            'message'   => 'Livraison créée avec succès.',
            'livraison' => $livraison->load([
                'commande:id,numero_commande,produit,quantite',
                'transporteur:id,utilisateur_id,type_vehicule,zone',
                'transporteur.utilisateur:id,nom,prenom,telephone',
            ]),
        ], 201);
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

        $statutActuel    = $livraison->statut;
        $nouveauStatut   = $request->statut;
        $transitionsOk   = $transitions[$statutActuel] ?? [];

        if (! in_array($nouveauStatut, $transitionsOk)) {
            return response()->json([
                'message'           => 'Transition de statut non autorisée.',
                'statut_actuel'     => $statutActuel,
                'transitions_valides' => $transitionsOk,
            ], 422);
        }

        $livraison->statut = $nouveauStatut;

        // Horodatage automatique à la livraison
        if ($nouveauStatut === 'livree') {
            $livraison->date_livraison = now();
        }

        $livraison->save();

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

        if ($livraison->statut === 'livree') {
            return response()->json([
                'message' => 'Impossible de signaler un problème sur une livraison déjà livrée.',
            ], 422);
        }

        if ($livraison->statut === 'probleme') {
            return response()->json([
                'message' => 'Un problème est déjà signalé sur cette livraison.',
            ], 422);
        }

        $livraison->statut = 'probleme';
        $livraison->save();

        // Règle métier 4 — l'alerte visuelle est portée par le flag alerte_livraison
        // dans GET /api/commandes/historique. Aucun changement de statut commande ici.

        return response()->json([
            'message'   => 'Problème signalé. L\'acheteur sera alerté dans son suivi de commande.',
            'livraison' => $livraison->fresh()->load('commande:id,numero_commande,statut'),
        ]);
    }

    // ── Helper privé ──────────────────────────────────────────────────────────

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
