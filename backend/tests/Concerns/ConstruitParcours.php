<?php

namespace Tests\Concerns;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Production;
use App\Models\Reservation;
use App\Models\Stock;
use App\Models\Utilisateur;

/**
 * Données fictives d'un parcours complet : lot du producteur réceptionné dans
 * l'entrepôt du gestionnaire, commandé par l'acheteur, livré par le transporteur.
 */
trait ConstruitParcours
{
    /** Requête authentifiée ; le garde Sanctum est réinitialisé (cache entre requêtes de test). */
    protected function en(Utilisateur $utilisateur): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($utilisateur->createToken('test')->plainTextToken);
    }

    protected function parcours(string $statutLivraison = 'en_cours'): object
    {
        $p = (object) [
            'producteur'   => Utilisateur::factory()->create(),
            'gestionnaire' => Utilisateur::factory()->gestionnaire()->create(),
            'acheteur'     => Utilisateur::factory()->acheteur()->create(),
            'transporteur' => Utilisateur::factory()->transporteur()->create(),
        ];

        $p->production  = $this->production($p->producteur);
        $p->reservation = $this->reservation($p->production, $p->gestionnaire, 'enregistree');
        $p->production->update(['statut' => 'disponible']);

        $p->stock = Stock::create([
            'entrepot_id'   => $p->gestionnaire->entrepot->id,
            'production_id' => $p->production->id,
            'produit'       => 'riz',
            'quantite'      => 80,
            'statut'        => 'disponible',
        ]);

        $p->commande = $this->commande($p->acheteur, $p->stock, 'confirmee');

        $p->livraison = Livraison::create([
            'commande_id'     => $p->commande->id,
            'transporteur_id' => $p->transporteur->transporteur->id,
            'origine'         => 'Entrepôt test',
            'destination'     => 'Dakar Plateau, rue 10',
            'statut'          => $statutLivraison,
        ]);

        // Livraison partie : la quantité réservée est déjà sortie du stock.
        if ($statutLivraison !== 'en_attente') {
            $p->commande->update(['etat_stock' => 'sorti']);
            $p->stock->update(['quantite_reservee' => 0]);
        }

        return $p;
    }

    protected function production(Utilisateur $producteur): Production
    {
        return Production::create([
            'producteur_id'    => $producteur->producteur->id,
            'type_culture'     => 'riz',
            'date_recolte'     => '2026-06-01',
            'quantite_estimee' => 100,
            'prix_unitaire'    => 150,
        ]);
    }

    protected function reservation(Production $production, Utilisateur $gestionnaire, string $statut): Reservation
    {
        return Reservation::create([
            'entrepot_id'       => $gestionnaire->entrepot->id,
            'producteur_id'     => $production->producteur_id,
            'production_id'     => $production->id,
            'produit'           => 'riz',
            'quantite_reservee' => 100,
            'date_debut'        => '2026-06-02',
            'date_reservation'  => now(),
            'statut'            => $statut,
        ]);
    }

    /** Le transporteur accepte la mission puis l'achemine jusqu'à l'entrepôt (étapes 5 à 8). */
    protected function acheminer(Utilisateur $transporteur, int $reservationId): void
    {
        $this->en($transporteur)->putJson("/api/reservations/{$reservationId}/accepter-mission")->assertOk();

        foreach (['au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot'] as $etape) {
            $this->en($transporteur)->putJson("/api/reservations/{$reservationId}/etape", ['etape' => $etape])->assertOk();
        }
    }

    /** Réception par le gestionnaire : code du lot attendu, état « bon », motif si la quantité diffère. */
    protected function receptionner(Utilisateur $gestionnaire, int $reservationId, float $quantite, array $extra = [])
    {
        $reservation = Reservation::with('production')->findOrFail($reservationId);
        $corps = array_merge([
            'quantite_reelle'  => $quantite,
            'code_lot'         => $reservation->production?->code_tracabilite,
            'etat_marchandise' => 'bon',
        ], abs($quantite - $reservation->quantite_reservee) > 1e-9 ? ['motif' => 'Écart constaté à la pesée'] : [], $extra);

        return $this->en($gestionnaire)->putJson("/api/reservations/{$reservationId}/valider", $corps);
    }

    /** Commande de 10 kg à 150 FCFA/kg ; confirmée, sa quantité est réservée sur le stock. */
    protected function commande(Utilisateur $acheteur, Stock $stock, string $statut = 'en_attente'): Commande
    {
        $confirmee = $statut === 'confirmee';

        if ($confirmee) {
            $stock->increment('quantite_reservee', 10);
        }

        return Commande::create([
            'acheteur_id'   => $acheteur->acheteurGros->id,
            'stock_id'      => $stock->id,
            'produit'       => 'riz',
            'quantite'      => 10,
            'prix_unitaire' => 150,
            'prix'          => 1500,
            'date_commande' => now(),
            'statut'        => $statut,
            'etat_stock'    => $confirmee ? 'reserve' : null,
        ]);
    }
}
