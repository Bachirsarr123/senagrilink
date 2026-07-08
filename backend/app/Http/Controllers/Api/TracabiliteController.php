<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Production;
use Illuminate\Http\JsonResponse;

class TracabiliteController extends Controller
{
    /**
     * Retourne le parcours complet d'un lot identifié par son code de traçabilité.
     *
     * Chaîne : Production → Producteur → Stocks (entrepôts, dates)
     *          → Commandes → Acheteurs → Livraisons → Transporteurs
     *
     * Accessible à tous les utilisateurs authentifiés (tous rôles).
     */
    public function show(string $code_tracabilite): JsonResponse
    {
        $production = Production::where('code_tracabilite', $code_tracabilite)
            ->with([
                // Origine : qui a produit et où
                'producteur:id,utilisateur_id,region,types_cultures',
                'producteur.utilisateur:id,nom,prenom',

                // Passage en entrepôt
                'stocks:id,production_id,entrepot_id,produit,quantite,date_entree,date_sortie,statut',
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
                'producteur'       => $production->producteur ? [
                    'nom'            => $production->producteur->utilisateur->nom,
                    'prenom'         => $production->producteur->utilisateur->prenom,
                    'region'         => $production->producteur->region,
                    'types_cultures' => $production->producteur->types_cultures,
                ] : null,
            ],
            'etapes_stockage'  => $production->stocks->map(fn ($stock) => [
                'entrepot'    => [
                    'nom'         => $stock->entrepot->nom_entrepot,
                    'localisation'=> $stock->entrepot->localisation,
                ],
                'produit'     => $stock->produit,
                'quantite'    => $stock->quantite,
                'date_entree' => $stock->date_entree,
                'date_sortie' => $stock->date_sortie,
                'statut'      => $stock->statut,
                'commandes'   => $stock->commandes->map(fn ($commande) => [
                    'numero_commande' => $commande->numero_commande,
                    'produit'         => $commande->produit,
                    'quantite'        => $commande->quantite,
                    'prix'            => $commande->prix,
                    'date_commande'   => $commande->date_commande,
                    'statut'          => $commande->statut,
                    'acheteur'        => $commande->acheteur ? [
                        'nom'          => $commande->acheteur->utilisateur->nom,
                        'prenom'       => $commande->acheteur->utilisateur->prenom,
                        'type_activite'=> $commande->acheteur->type_activite,
                    ] : null,
                    'livraison'       => $commande->livraison ? [
                        'id'               => $commande->livraison->id,
                        'numero_livraison' => $commande->livraison->numero_livraison,
                        'origine'          => $commande->livraison->origine,
                        'destination'      => $commande->livraison->destination,
                        'date_depart'      => $commande->livraison->date_depart,
                        'date_livraison'   => $commande->livraison->date_livraison,
                        'statut'           => $commande->livraison->statut,
                        'transporteur'     => $commande->livraison->transporteur ? [
                            'nom'          => $commande->livraison->transporteur->utilisateur->nom,
                            'prenom'       => $commande->livraison->transporteur->utilisateur->prenom,
                            'type_vehicule'=> $commande->livraison->transporteur->type_vehicule,
                            'zone'         => $commande->livraison->transporteur->zone,
                        ] : null,
                    ] : null,
                ]),
            ]),
        ];

        return response()->json(['tracabilite' => $parcours]);
    }
}
