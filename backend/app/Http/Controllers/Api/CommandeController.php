<?php

namespace App\Http\Controllers\Api;

use App\Support\Lieux;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommandeRequest;
use App\Http\Requests\UpdateCommandeRequest;
use App\Exceptions\StockInsuffisantException;
use App\Models\Commande;
use App\Models\Incident;
use App\Models\Livraison;
use App\Models\Transporteur;
use App\Models\HistoriqueOperation;
use App\Models\Stock;
use App\Notifications\CommandeConfirmeeNotification;
use App\Notifications\NouvelleCommandeNotification;
use App\Notifications\OperationModifieeNotification;
use App\Services\AnnulationCommande;
use App\Services\GestionStock;
use App\Services\ReceptionCommande;
use App\Support\CircuitLivraison;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommandeController extends Controller
{
    public function __construct(
        private readonly GestionStock $gestionStock,
        private readonly AnnulationCommande $annulation,
    ) {
    }

    // ── Acheteur en gros ─────────────────────────────────────────────────────

    /**
     * Catalogue : stocks ayant une quantité disponible (physique - réservé) et
     * un prix fixé par le producteur du lot. « quantite » est le disponible.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $stocks = Stock::where('statut', 'disponible')
            ->whereColumn('quantite', '>', 'quantite_reservee')
            ->whereHas('production', fn ($q) => $q->whereNotNull('prix_unitaire'))
            ->with([
                'entrepot:id,nom_entrepot,localisation',
                'production:id,code_tracabilite,type_culture,date_recolte,prix_unitaire',
            ])
            ->orderBy('produit')
            ->get()
            ->map(fn ($stock) => [
                'stock_id'            => $stock->id,
                'produit'             => $stock->produit,
                'quantite'            => $stock->quantite_disponible,
                'quantite_disponible' => $stock->quantite_disponible,
                'prix_unitaire'       => $stock->production->prix_unitaire,
                'date_entree'         => $stock->date_entree,
                'entrepot'            => $stock->entrepot,
                'production'          => $stock->production,
            ]);

        return response()->json(['catalogue' => $stocks]);
    }

    /**
     * Passer une commande, ou un panier de plusieurs produits (lignes[]).
     *
     * Règle métier 1 : chaque quantité demandée <= disponible (physique - réservé).
     * Tout ou rien : si une ligne est impossible, aucune commande n'est créée.
     * Le prix au kilo du lot (fixé par le producteur) est figé sur chaque commande ;
     * les commandes d'un même panier partagent sa référence.
     */
    public function store(StoreCommandeRequest $request): JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $lignes = $request->has('lignes')
            ? $request->input('lignes')
            : [['stock_id' => $request->stock_id, 'quantite' => $request->quantite]];
        [$livLat, $livLng, $livAdresse] = Lieux::retenir($request, 'livraison', $acheteur, $request->user()->adresse);
        $panier = count($lignes) > 1 ? 'PAN-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)) : null;

        // Une ligne impossible lève une exception : la transaction annule tout le panier.
        $resultat = DB::transaction(function () use ($lignes, $acheteur, $panier, $request, $livLat, $livLng, $livAdresse) {
            $commandes = [];

            foreach ($lignes as $index => $ligne) {
                $stock    = Stock::with('production:id,producteur_id,prix_unitaire')->whereKey($ligne['stock_id'])->lockForUpdate()->first();
                $quantite = (float) $ligne['quantite'];
                $contexte = count($lignes) > 1 ? ['ligne' => $index, 'produit' => $stock?->produit] : [];

                if ($stock->statut !== 'disponible') {
                    throw new HttpResponseException(response()->json(['message' => 'Ce produit n\'est plus disponible en stock.', 'statut' => $stock->statut, ...$contexte], 422));
                }

                if ($quantite > $stock->quantite_disponible) {
                    throw new HttpResponseException(response()->json([
                        'message'             => 'Quantité insuffisante en stock. Commande non créée.',
                        'quantite_disponible' => $stock->quantite_disponible,
                        'quantite_demandee'   => $quantite,
                        ...$contexte,
                    ], 422));
                }

                $prixUnitaire = $stock->production?->prix_unitaire;

                if ($prixUnitaire === null) {
                    throw new HttpResponseException(response()->json(['message' => 'Le producteur n\'a pas encore fixé le prix de ce lot : il ne peut pas être commandé.', ...$contexte], 422));
                }

                $commande = Commande::create([
                    'acheteur_id'   => $acheteur->id,
                    'stock_id'      => $stock->id,
                    'produit'       => $stock->produit,
                    'quantite'      => $quantite,
                    'prix_unitaire' => $prixUnitaire,
                    'prix'          => round($prixUnitaire * $quantite, 2),
                    'panier'        => $panier,
                    'livraison_latitude'  => $livLat,
                    'livraison_longitude' => $livLng,
                    'livraison_adresse'   => $livAdresse,
                    'date_commande' => now(),
                    'statut'        => 'en_attente',
                ]);

                HistoriqueOperation::consigner('commande', $commande->id, 'creation', $request->user(), null, 'en_attente', null, [
                    'quantite' => $quantite, 'prix_unitaire' => (float) $prixUnitaire, 'montant' => (float) $commande->prix, 'panier' => $panier,
                ]);

                $commandes[] = [$commande, $stock];
            }

            return $commandes;
        });

        // Notifie le producteur du lot et le gestionnaire de l'entrepôt de chaque ligne
        foreach ($resultat as [$commande, $stock]) {
            $stock->loadMissing(['production.producteur.utilisateur', 'entrepot.utilisateur']);
            $stock->production?->producteur?->utilisateur?->notify(new NouvelleCommandeNotification($commande));
            $stock->entrepot?->utilisateur?->notify(new NouvelleCommandeNotification($commande));
        }

        // Lot 11 : confirmation automatique (le stock est déjà réservé)
        app(\App\Services\PiloteAutomatique::class)->commandesPassees(collect($resultat)->map(fn ($r) => $r[0]->load('stock.entrepot')));
        $commandes = collect($resultat)->map(fn ($r) => $r[0]->fresh()->load('stock.entrepot'));

        return response()->json([
            'message'   => $commandes->count() > 1 ? sprintf('Panier commandé : %d commandes passées.', $commandes->count()) : 'Commande passée avec succès.',
            'commande'  => $commandes->first(),
            'commandes' => $commandes,
            'panier'    => $panier,
        ], 201);
    }

    /**
     * Modifier la quantité d'une commande (le prix au kilo reste celui figé à
     * la commande ; le montant est recalculé). Uniquement si statut = en_attente.
     */
    public function update(UpdateCommandeRequest $request, int $id): JsonResponse
    {
        $commande = $this->commandeDeLAcheteur($request, $id);

        if ($commande instanceof JsonResponse) {
            return $commande;
        }

        // Verrou + revérification : une modification envoyée pendant la
        // confirmation ne peut plus s'appliquer à une commande déjà confirmée
        // (stock déduit de l'ancienne quantité, commande affichant la nouvelle).
        $refus = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être modifiées.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            // Si la quantité change, re-vérifier la disponibilité (règle métier 1)
            if ($request->has('quantite')) {
                $stock = Stock::whereKey($c->stock_id)->lockForUpdate()->first();

                if (! $stock || $request->quantite > $stock->quantite_disponible) {
                    return response()->json([
                        'message'             => 'Quantité insuffisante en stock.',
                        'quantite_disponible' => $stock?->quantite_disponible ?? 0,
                        'quantite_demandee'   => $request->quantite,
                    ], 422);
                }

                $ancienne     = (float) $c->quantite;
                $c->quantite  = $request->quantite;
                $c->prix      = $c->prix_unitaire !== null ? round($c->prix_unitaire * $request->quantite, 2) : $c->prix;
                $c->save();

                HistoriqueOperation::consigner('commande', $c->id, 'modification', $request->user(), 'en_attente', 'en_attente', null, [
                    'quantite_avant' => $ancienne, 'quantite_apres' => (float) $c->quantite,
                ]);
            }

            return null;
        });

        if ($refus) {
            return $refus;
        }

        return response()->json([
            'message'  => 'Commande mise à jour.',
            'commande' => $commande->fresh()->load('stock'),
        ]);
    }

    /**
     * Annuler une commande.
     * Uniquement possible si statut = en_attente (le stock n'a pas encore été déduit).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $commande = $this->commandeDeLAcheteur($request, $id);

        if ($commande instanceof JsonResponse) {
            return $commande;
        }

        $refus = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être annulées. Une commande confirmée est déjà préparée par l\'entrepôt : contactez-le.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            $c->statut = 'annulee';
            $c->save();
            HistoriqueOperation::consigner('commande', $c->id, 'annulation', $request->user(), 'en_attente', 'annulee', $request->input('motif'));

            return null;
        });

        if ($refus) {
            return $refus;
        }

        return response()->json([
            'message'  => 'Commande annulée.',
            'commande' => $commande->fresh(),
        ]);
    }

    /**
     * Historique des commandes de l'acheteur connecté, avec pour chacune ses
     * actions possibles, l'étape attendue, ses incidents ouverts et le code de
     * réception (donné au transporteur à la remise).
     * alerte_livraison signale un incident ouvert sur la livraison.
     */
    public function historique(Request $request): JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $commandes = Commande::where('acheteur_id', $acheteur->id)
            ->with([
                'stock:id,produit,entrepot_id',
                'stock.entrepot:id,nom_entrepot,localisation',
                'livraison:id,commande_id,statut,transporteur_id,date_depart,date_livraison,numero_livraison,parti_le,arrive_le',
                'livraison.transporteur:id,utilisateur_id,type_vehicule',
                'livraison.transporteur.utilisateur:id,nom,prenom,telephone',
                'livraison.incidents' => fn ($q) => $q->ouverts(),
                'incidents',
            ])
            ->orderBy('date_commande', 'desc')
            ->get()
            ->map(fn (Commande $c) => $this->presenter($c, $request->user(), true));

        return response()->json(['commandes' => $commandes]);
    }

    /** Commande + actions, étape attendue et incidents (même forme pour l'acheteur et l'entrepôt). */
    private function presenter(Commande $c, $utilisateur, bool $pourAcheteur): array
    {
        $demandeOuverte = $c->incidents->contains(fn ($i) => $i->statut === 'ouvert' && $i->type === 'demande_annulation');
        $alerte         = (bool) $c->livraison?->incidents?->isNotEmpty();

        return array_merge($c->toArray(), [
            'actions'          => CircuitLivraison::actionsCommande($c, $utilisateur, $demandeOuverte),
            'prochaine_etape'  => CircuitLivraison::prochaineEtape($c),
            'alerte_livraison' => $alerte,
            'code_reception'   => $pourAcheteur && $c->statut === 'en_livraison' ? $c->code_reception : null,
        ]);
    }

    /** L'acheteur confirme lui-même la réception de la livraison arrivée. */
    public function confirmerReception(Request $request, int $id): JsonResponse
    {
        $commande = $this->commandeDeLAcheteur($request, $id);

        if ($commande instanceof JsonResponse) {
            return $commande;
        }

        $refus = DB::transaction(function () use ($commande, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();
            $l = Livraison::where('commande_id', $c->id)->where('statut', 'arrivee')->lockForUpdate()->first();

            if ($c->statut !== 'en_livraison' || ! $l) {
                return response()->json([
                    'message'       => 'La réception se confirme quand le transporteur est arrivé.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            app(ReceptionCommande::class)->receptionner($c, $l, $request->user(), 'acheteur');

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $livraison = $commande->fresh()->livraison;
        app(LivraisonController::class)->notifierFin($livraison);
        Transporteur::with('utilisateur')->find($livraison->transporteur_id)?->utilisateur?->notify(new OperationModifieeNotification(
            'reception_confirmee', sprintf('L\'acheteur a confirmé la réception de la livraison %s. Mission terminée.', $livraison->numero_livraison),
            ['livraison_id' => $livraison->id, 'numero_livraison' => $livraison->numero_livraison],
        ));

        return response()->json(['message' => 'Réception confirmée. Merci !', 'commande' => $commande->fresh()]);
    }

    /** Demande d'annulation (après confirmation, avant chargement) : l'entrepôt décide. */
    public function demanderAnnulation(Request $request, int $id): JsonResponse
    {
        return $this->declarer($request, $id, 'demande_annulation', $request->input('motif') ?: 'Demande d\'annulation de l\'acheteur.');
    }

    /** Anomalie à la réception (quantité manquante, produit abîmé, livraison incorrecte) : la commande passe en litige. */
    public function declarerAnomalie(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'type'        => ['required', Rule::in(Incident::ANOMALIES)],
            'description' => ['required', 'string', 'max:1000'],
        ], ['type.required' => 'Choisissez le type d\'anomalie.', 'description.required' => 'Décrivez l\'anomalie constatée.']);

        return $this->declarer($request, $id, $request->type, $request->description);
    }

    private function declarer(Request $request, int $id, string $type, string $description): JsonResponse
    {
        $request->merge(['sujet_type' => 'commande', 'sujet_id' => $id, 'type' => $type, 'description' => $description]);

        return app(IncidentController::class)->store($request);
    }

    // ── Gestionnaire d'entrepôt ───────────────────────────────────────────────

    /**
     * Confirmer une commande en_attente.
     *
     * Règle métier 1 : quantité disponible >= quantité commandée, sinon 422.
     * Règle métier 2 : la confirmation RÉSERVE la quantité (atomique) ; elle
     * ne sortira physiquement du stock qu'au départ de la livraison.
     */
    public function confirmer(Request $request, int $id): JsonResponse
    {
        $commande = Commande::with(['stock', 'acheteur.utilisateur'])->findOrFail($id);

        // Contrôle de périmètre avant toute autre vérification et toute écriture
        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        if ($commande->statut !== 'en_attente') {
            return response()->json([
                'message'       => 'Seules les commandes en attente peuvent être confirmées.',
                'statut_actuel' => $commande->statut,
            ], 422);
        }

        $stock = $commande->stock;

        if (! $stock) {
            return response()->json([
                'message' => 'Aucun stock associé à cette commande.',
            ], 422);
        }

        // Règle métier 2 — transaction atomique : confirmation + réservation.
        // Commande et stock sont verrouillés (FOR UPDATE) puis revérifiés : deux
        // confirmations simultanées ne peuvent pas réserver deux fois la quantité.
        $refus = DB::transaction(function () use ($commande, &$stock, $request) {
            $commandeVerrouillee = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($commandeVerrouillee->statut !== 'en_attente') {
                return response()->json([
                    'message'       => 'Seules les commandes en attente peuvent être confirmées.',
                    'statut_actuel' => $commandeVerrouillee->statut,
                ], 422);
            }

            try {
                $stock = $this->gestionStock->reserver(
                    $commandeVerrouillee->stock_id, (float) $commandeVerrouillee->quantite, $request->user(), 'commande', $commandeVerrouillee->id,
                );
            } catch (StockInsuffisantException $e) {
                return response()->json([
                    'message'             => 'Stock insuffisant pour confirmer cette commande.',
                    'quantite_disponible' => $e->disponible,
                    'quantite_demandee'   => $e->demandee,
                ], 422);
            }

            $commandeVerrouillee->statut     = 'confirmee';
            $commandeVerrouillee->etat_stock = 'reserve';
            $commandeVerrouillee->save();

            HistoriqueOperation::consigner('commande', $commandeVerrouillee->id, 'confirmation', $request->user(), 'en_attente', 'confirmee', null, [
                'quantite_reservee' => (float) $commandeVerrouillee->quantite,
            ]);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $commande->acheteur?->utilisateur?->notify(new CommandeConfirmeeNotification($commande));

        return response()->json([
            'message'       => 'Commande confirmée. Quantité réservée dans le stock.',
            'commande'      => $commande->fresh()->load('stock'),
            'stock_restant' => $stock->quantite_disponible,
        ]);
    }

    /**
     * Commandes portant sur les stocks de l'entrepôt du gestionnaire connecté
     * (toutes pour l'administrateur), avec la livraison et son transporteur.
     * Filtre facultatif : ?statut=en_attente|confirmee|annulee|livree.
     */
    public function indexEntrepot(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        $request->validate(['statut' => ['nullable', 'in:en_attente,confirmee,en_preparation,prete,en_livraison,receptionnee,litige,annulee']]);

        $query = Commande::with([
            'acheteur:id,utilisateur_id',
            'acheteur.utilisateur:id,nom,prenom,telephone',
            'stock:id,entrepot_id,produit',
            'livraison:id,commande_id,numero_livraison,statut,transporteur_id,date_depart,mission_acceptee_le,parti_le,arrive_le,date_livraison',
            'livraison.transporteur:id,utilisateur_id,type_vehicule',
            'livraison.transporteur.utilisateur:id,nom,prenom,telephone',
            'livraison.incidents' => fn ($q) => $q->ouverts(),
            'incidents',
            'historique.utilisateur:id,nom,prenom,role',
        ])->when($request->statut, fn ($q, $statut) => $q->where('statut', $statut))
          ->orderByDesc('date_commande');

        if ($utilisateur->role !== 'administrateur') {
            $entrepot = $utilisateur->entrepot;

            if (! $entrepot) {
                return response()->json(['message' => 'Profil entrepôt introuvable.'], 404);
            }

            $query->whereHas('stock', fn ($q) => $q->where('entrepot_id', $entrepot->id));
        }

        return response()->json(['commandes' => $query->get()->map(fn (Commande $c) => $this->presenter($c, $utilisateur, false))]);
    }

    /** Lancer la préparation d'une commande confirmée. */
    public function preparer(Request $request, int $id): JsonResponse
    {
        return $this->avancer($request, $id, 'confirmee', 'en_preparation', 'preparation', 'Préparation lancée.',
            'Votre commande %s est en préparation à l\'entrepôt.');
    }

    /** Commande préparée, prête à être chargée : la livraison peut être organisée. */
    public function marquerPrete(Request $request, int $id): JsonResponse
    {
        $reponse = $this->avancer($request, $id, 'en_preparation', 'prete', 'prete', 'Commande prête. Organisez maintenant sa livraison.',
            'Votre commande %s est prête : sa livraison va être organisée.');

        // Lot 11 : la livraison est confiée d'office au transporteur libre le plus proche
        if ($reponse->isSuccessful() && ! \App\Services\PiloteAutomatique::$enCours) {
            $c = Commande::with('stock.entrepot', 'livraison')->find($id);
            app(\App\Services\PiloteAutomatique::class)->commandePrete($c);
            if ($c->fresh()->livraison) {
                $donnees = $reponse->getData(true);
                $donnees['message'] = 'Commande prête. Un transporteur a été prévenu automatiquement.';

                return response()->json($donnees, $reponse->getStatusCode());
            }
        }

        return $reponse;
    }

    private function avancer(Request $request, int $id, string $depuis, string $vers, string $action, string $message, string $pourAcheteur): JsonResponse
    {
        $commande = Commande::with(['stock', 'acheteur.utilisateur'])->find($id);

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $refus = DB::transaction(function () use ($commande, $depuis, $vers, $action, $request) {
            $c = Commande::whereKey($commande->id)->lockForUpdate()->first();

            if ($c->statut !== $depuis) {
                return response()->json(['message' => 'Cette étape n\'est pas possible maintenant : les étapes se suivent dans l\'ordre.', 'statut_actuel' => $c->statut], 422);
            }

            $c->statut = $vers;
            $c->save();
            HistoriqueOperation::consigner('commande', $c->id, $action, $request->user(), $depuis, $vers);

            return null;
        });

        if ($refus) {
            return $refus;
        }

        $commande->acheteur?->utilisateur?->notify(new OperationModifieeNotification("commande_{$vers}", sprintf($pourAcheteur, $commande->numero_commande),
            ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande]));

        return response()->json(['message' => $message, 'commande' => $commande->fresh()]);
    }

    /** Refus par l'entrepôt d'une commande en attente (motif obligatoire, acheteur notifié). */
    public function refuser(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif du refus.']);

        return $this->annulerParLEntrepot($request, $id, 'en_attente', 'refus', 'Commande refusée. L\'acheteur a été notifié.');
    }

    /**
     * Annulation par l'entrepôt d'une commande confirmée, en préparation ou
     * prête, tant que la marchandise n'est pas chargée : la quantité réservée
     * est libérée et la livraison éventuelle annulée (transporteur prévenu).
     */
    public function annulerConfirmation(Request $request, int $id): JsonResponse
    {
        $request->validate(['motif' => ['required', 'string', 'max:500']], ['motif.required' => 'Indiquez le motif de l\'annulation.']);

        return $this->annulerParLEntrepot($request, $id, 'confirmee', 'annulation_confirmation', 'Commande annulée, quantité réservée libérée. L\'acheteur a été notifié.');
    }

    private function annulerParLEntrepot(Request $request, int $id, string $depuis, string $action, string $messageSucces): JsonResponse
    {
        $commande = Commande::with(['stock', 'acheteur.utilisateur'])->find($id);

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        if (! $commande->estGereePar($request->user())) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        $transporteur = null;
        $refus = DB::transaction(function () use ($commande, $depuis, $action, $request, &$transporteur) {
            $c = Commande::with('livraison')->whereKey($commande->id)->lockForUpdate()->first();

            if ($depuis === 'en_attente') {
                if ($c->statut !== 'en_attente') {
                    return response()->json(['message' => 'Seules les commandes en attente peuvent être refusées.', 'statut_actuel' => $c->statut], 422);
                }

                $c->statut = 'annulee';
                $c->save();
                HistoriqueOperation::consigner('commande', $c->id, $action, $request->user(), 'en_attente', 'annulee', $request->input('motif'));

                return null;
            }

            if (! in_array('annuler_commande', CircuitLivraison::actionsCommande($c, $request->user()), true)) {
                return response()->json([
                    'message'       => in_array($c->statut, ['en_livraison', 'receptionnee', 'litige'], true)
                        ? 'La marchandise est déjà chargée : déclarez un incident pour la faire revenir.'
                        : 'Cette commande ne peut pas être annulée par l\'entrepôt.',
                    'statut_actuel' => $c->statut,
                ], 422);
            }

            $transporteur = $this->annulation->annuler($c, $request->user(), $action, $request->input('motif'));

            return null;
        });

        if ($refus) {
            return $refus;
        }

        if ($transporteur) {
            Transporteur::with('utilisateur')->find($transporteur)?->utilisateur?->notify(new OperationModifieeNotification('mission_annulee',
                sprintf('La livraison de la commande %s est annulée par l\'entrepôt.', $commande->numero_commande), ['commande_id' => $commande->id]));
        }

        $commande->acheteur?->utilisateur?->notify(new OperationModifieeNotification("commande_{$action}", sprintf(
            'Votre commande %s (%s) a été %s par l\'entrepôt. Motif : %s',
            $commande->numero_commande, $commande->produit, $depuis === 'en_attente' ? 'refusée' : 'annulée', $request->input('motif'),
        ), ['commande_id' => $commande->id, 'numero_commande' => $commande->numero_commande]));

        return response()->json(['message' => $messageSucces, 'commande' => $commande->fresh()->load('stock')]);
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    /**
     * Récupère une commande appartenant à l'acheteur connecté.
     * Retourne une JsonResponse 403/404 si la commande est inaccessible.
     */
    private function commandeDeLAcheteur(Request $request, int $id): Commande|JsonResponse
    {
        $acheteur = $request->user()->acheteurGros;

        if (! $acheteur) {
            return response()->json(['message' => 'Profil acheteur introuvable.'], 404);
        }

        $commande = Commande::with('stock')->find($id);

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        if ($commande->acheteur_id !== $acheteur->id) {
            return response()->json(['message' => 'Accès non autorisé à cette commande.'], 403);
        }

        return $commande;
    }
}
