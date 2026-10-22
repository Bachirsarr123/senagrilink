<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Production;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TracabiliteController extends Controller
{
    /**
     * Retourne le parcours complet d'un lot identifié par son code de traçabilité.
     *
     * Chaîne : Production → Producteur → Stocks (entrepôts, dates)
     *          → Commandes → Acheteurs → Livraisons → Transporteurs
     *
     * Accessible à tous les utilisateurs authentifiés (tous rôles). R7 — le prix,
     * l'identité de l'acheteur, l'adresse de livraison et l'identité du
     * transporteur ne sont fournis qu'aux acteurs concernés (voir partieConcernee).
     */
    public function show(Request $request, string $code_tracabilite): JsonResponse
    {
        $production = Production::where('code_tracabilite', $code_tracabilite)
            ->with([
                // Origine : qui a produit et où
                'producteur:id,utilisateur_id,region,types_cultures',
                'producteur.utilisateur:id,nom,prenom',

                // Acheminement vers l'entrepôt (circuit A) et ses étapes datées
                'reservations' => fn ($q) => $q->whereNotIn('statut', ['annulee', 'refusee'])->orderBy('id'),
                'reservations.entrepot:id,nom_entrepot,localisation',
                'reservations.transporteur:id,utilisateur_id,type_vehicule',
                'reservations.transporteur.utilisateur:id,nom,prenom',
                'reservations.historique:id,sujet_type,sujet_id,action,created_at',

                // Passage en entrepôt
                'stocks:id,production_id,entrepot_id,produit,quantite,quantite_reservee,date_entree,date_sortie,statut',
                'stocks.entrepot:id,nom_entrepot,localisation',

                // Commandes passées sur ces stocks
                'stocks.commandes:id,stock_id,numero_commande,produit,quantite,prix,date_commande,statut,acheteur_id',
                'stocks.commandes.acheteur:id,utilisateur_id,type_activite',
                'stocks.commandes.acheteur.utilisateur:id,nom,prenom',

                // Livraisons associées aux commandes
                'stocks.commandes.livraison:id,commande_id,numero_livraison,origine,destination,date_depart,date_livraison,statut,transporteur_id',
                'stocks.commandes.livraison.transporteur:id,utilisateur_id,type_vehicule,zone',
                'stocks.commandes.livraison.transporteur.utilisateur:id,nom,prenom',
            ])
            ->first();

        if (! $production) {
            return response()->json([
                'message'           => 'Aucun lot trouvé avec ce code de traçabilité.',
                'code_tracabilite'  => $code_tracabilite,
            ], 404);
        }

        $lecteur        = $request->user();
        $producteurDuLot = $lecteur->role === 'administrateur'
            || ($lecteur->producteur !== null && (int) $lecteur->producteur->id === (int) $production->producteur_id);

        // Construction de la réponse structurée par étape de la chaîne
        $parcours = [
            'code_tracabilite' => $production->code_tracabilite,
            'etape_production' => [
                'id'               => $production->id,
                'type_culture'     => $production->type_culture,
                'superficie'       => $production->superficie,
                'date_recolte'     => $production->date_recolte,
                'quantite_estimee' => $production->quantite_estimee,
                'quantite_reelle'  => $production->quantite_reelle,
                'statut'           => $production->statut,
                // Origine du lot : information de traçabilité publique
                'producteur'       => $production->producteur?->utilisateur ? [
                    'nom'            => $production->producteur->utilisateur->nom,
                    'prenom'         => $production->producteur->utilisateur->prenom,
                    'region'         => $production->producteur->region,
                    'types_cultures' => $production->producteur->types_cultures,
                ] : null,
            ],
            'etapes_acheminement' => $production->reservations->map(
                fn (Reservation $r) => $this->etapeAcheminement($r, $lecteur, $producteurDuLot)
            )->values(),
            'etapes_stockage'  => $production->stocks->map(fn (Stock $stock) => [
                'entrepot'    => [
                    'nom'         => $stock->entrepot?->nom_entrepot,
                    'localisation'=> $stock->entrepot?->localisation,
                ],
                'produit'     => $stock->produit,
                'quantite'    => $stock->quantite,
                'date_entree' => $stock->date_entree,
                'date_sortie' => $stock->date_sortie,
                'statut'      => $stock->statut,
                'commandes'   => $stock->commandes->map(
                    fn (Commande $commande) => $this->etapeCommande($commande, $stock, $lecteur, $producteurDuLot)
                ),
            ]),
        ];

        return response()->json(['tracabilite' => $parcours]);
    }

    /** Étapes de transport et de réception, datées ; le transporteur n'est nommé qu'aux acteurs concernés. */
    private const ETAPES_DATEES = [
        'creation'            => 'Dépôt demandé',
        'confirmation'        => 'Dépôt accepté par l\'entrepôt',
        'acceptation_mission' => 'Mission acceptée par le transporteur',
        'au_point_collecte'   => 'Transporteur arrivé chez le producteur',
        'chargee'             => 'Marchandise chargée',
        'en_route'            => 'En route vers l\'entrepôt',
        'arrivee_entrepot'    => 'Arrivée à l\'entrepôt',
        'reception'           => 'Réception validée',
        'reception_partielle' => 'Réception validée avec un écart',
        'refus_reception'     => 'Réception refusée',
    ];

    private function etapeAcheminement(Reservation $r, Utilisateur $lecteur, bool $producteurDuLot): array
    {
        $concerne = $producteurDuLot
            || ($lecteur->entrepot !== null && (int) $lecteur->entrepot->id === (int) $r->entrepot_id)
            || ($lecteur->transporteur !== null && (int) $lecteur->transporteur->id === (int) $r->transporteur_id);

        return [
            'numero_reservation' => $r->numero_reservation,
            'entrepot'           => ['nom' => $r->entrepot?->nom_entrepot, 'localisation' => $r->entrepot?->localisation],
            'mode_transport'     => $r->mode_transport,
            'quantite_prevue'    => $r->quantite_reservee,
            'quantite_recue'     => $r->quantite_recue,
            'etat_marchandise'   => $r->etat_marchandise,
            'statut'             => $r->statut,
            'transporteur'       => $concerne && $r->transporteur?->utilisateur ? [
                'nom'           => $r->transporteur->utilisateur->nom,
                'prenom'        => $r->transporteur->utilisateur->prenom,
                'type_vehicule' => $r->transporteur->type_vehicule,
            ] : null,
            'etapes'             => $r->historique
                ->filter(fn ($h) => isset(self::ETAPES_DATEES[$h->action]))
                ->map(fn ($h) => ['action' => $h->action, 'libelle' => self::ETAPES_DATEES[$h->action], 'date' => $h->created_at?->toIso8601String()])
                ->values(),
        ];
    }

    /**
     * Données d'une commande dans le parcours. Le prix n'est donné qu'aux
     * parties commerciales (admin, producteur du lot, gestionnaire de l'entrepôt,
     * acheteur de la commande) ; l'identité de l'acheteur, la destination et
     * l'identité du transporteur, qu'à ces parties et au transporteur de la livraison.
     */
    private function etapeCommande(Commande $commande, Stock $stock, Utilisateur $lecteur, bool $producteurDuLot): array
    {
        $livraison = $commande->livraison;

        $partieCommerciale = $producteurDuLot
            || ($lecteur->entrepot !== null && (int) $lecteur->entrepot->id === (int) $stock->entrepot_id)
            || ($lecteur->acheteurGros !== null && (int) $lecteur->acheteurGros->id === (int) $commande->acheteur_id);

        $partieConcernee = $partieCommerciale
            || ($livraison && $lecteur->transporteur !== null && (int) $lecteur->transporteur->id === (int) $livraison->transporteur_id);

        return [
            'numero_commande'        => $commande->numero_commande,
            'produit'                => $commande->produit,
            'quantite'               => $commande->quantite,
            'prix'                   => $partieCommerciale ? $commande->prix : null,
            'date_commande'          => $commande->date_commande,
            'statut'                 => $commande->statut,
            'informations_masquees'  => ! $partieConcernee || ! $partieCommerciale,
            'acheteur'               => $partieConcernee && $commande->acheteur?->utilisateur ? [
                'nom'          => $commande->acheteur->utilisateur->nom,
                'prenom'       => $commande->acheteur->utilisateur->prenom,
                'type_activite'=> $commande->acheteur->type_activite,
            ] : null,
            'livraison'              => $livraison ? [
                'id'               => $livraison->id,
                'numero_livraison' => $livraison->numero_livraison,
                'origine'          => $livraison->origine,
                'destination'      => $partieConcernee ? $livraison->destination : null,
                'date_depart'      => $livraison->date_depart,
                'date_livraison'   => $livraison->date_livraison,
                'statut'           => $livraison->statut,
                'transporteur'     => $partieConcernee && $livraison->transporteur?->utilisateur ? [
                    'nom'          => $livraison->transporteur->utilisateur->nom,
                    'prenom'       => $livraison->transporteur->utilisateur->prenom,
                    'type_vehicule'=> $livraison->transporteur->type_vehicule,
                    'zone'         => $livraison->transporteur->zone,
                ] : null,
            ] : null,
        ];
    }
}
