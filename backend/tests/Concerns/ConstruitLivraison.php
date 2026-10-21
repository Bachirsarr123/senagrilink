<?php

namespace Tests\Concerns;

use App\Models\Commande;
use App\Models\Production;
use App\Models\Stock;
use App\Models\Utilisateur;

/**
 * Circuit « entrepôt → acheteur » joué par l'API : un lot de 80 kg à 150 FCFA/kg
 * en stock, une commande de l'acheteur menée jusqu'à l'étape voulue.
 */
trait ConstruitLivraison
{
    use ConstruitParcours;

    protected Utilisateur $producteur;
    protected Utilisateur $gestionnaire;
    protected Utilisateur $acheteur;
    protected Utilisateur $transporteurA;
    protected Utilisateur $transporteurB;
    protected Stock $stock;

    protected function preparerLivraison(): void
    {
        $this->producteur    = Utilisateur::factory()->create();
        $this->gestionnaire  = Utilisateur::factory()->gestionnaire()->create();
        $this->acheteur      = Utilisateur::factory()->acheteur()->create();
        $this->transporteurA = Utilisateur::factory()->transporteur()->create();
        $this->transporteurB = Utilisateur::factory()->transporteur()->create();
        $this->stock         = $this->lot(80);
    }

    protected function lot(float $quantite): Stock
    {
        $production = $this->production($this->producteur);
        $production->update(['statut' => 'disponible']);

        return Stock::create([
            'entrepot_id' => $this->gestionnaire->entrepot->id, 'production_id' => $production->id,
            'produit' => 'riz', 'quantite' => $quantite, 'statut' => 'disponible',
        ]);
    }

    /** Commande de $quantite kg menée jusqu'à $jusqua : confirmee, prete, organisee, acceptee, chargee, en_cours, arrivee. */
    protected function commandeJusqua(string $jusqua, float $quantite = 10): Commande
    {
        $id = $this->en($this->acheteur)->postJson('/api/commandes', ['stock_id' => $this->stock->id, 'quantite' => $quantite])
                   ->assertStatus(201)->json('commande.id');
        $etapes = ['confirmee', 'prete', 'organisee', 'acceptee', 'chargee', 'en_cours', 'arrivee'];

        foreach (array_slice($etapes, 0, array_search($jusqua, $etapes, true) + 1) as $etape) {
            match ($etape) {
                'confirmee' => $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/confirmer")->assertOk(),
                'prete'     => [
                    $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/preparer")->assertOk(),
                    $this->en($this->gestionnaire)->putJson("/api/commandes/{$id}/prete")->assertOk(),
                ],
                'organisee' => $this->en($this->gestionnaire)->postJson('/api/livraisons', [
                    'commande_id' => $id, 'transporteur_id' => $this->transporteurA->transporteur->id,
                ])->assertStatus(201),
                'acceptee'  => $this->en($this->transporteurA)->putJson("/api/livraisons/{$this->livraisonId($id)}/accepter-mission")->assertOk(),
                default     => $this->etapeLivraison($this->transporteurA, $id, $etape)->assertOk(),
            };
        }

        return Commande::find($id);
    }

    protected function livraisonId(int $commandeId): int
    {
        return Commande::find($commandeId)->livraison->id;
    }

    protected function etapeLivraison(Utilisateur $t, int $commandeId, string $etape)
    {
        return $this->en($t)->putJson("/api/livraisons/{$this->livraisonId($commandeId)}/etape", ['etape' => $etape]);
    }

    protected function vueAcheteur(int $commandeId): array
    {
        return collect($this->en($this->acheteur)->getJson('/api/commandes/historique')->assertOk()->json('commandes'))->firstWhere('id', $commandeId);
    }

    protected function vueEntrepot(int $commandeId): array
    {
        return collect($this->en($this->gestionnaire)->getJson('/api/commandes')->assertOk()->json('commandes'))->firstWhere('id', $commandeId);
    }

    protected function vueTransporteur(Utilisateur $t, int $commandeId): ?array
    {
        return collect($this->en($t)->getJson('/api/livraisons')->assertOk()->json('livraisons'))->firstWhere('commande_id', $commandeId);
    }

    protected function typesNotifications(Utilisateur $u): array
    {
        return $this->en($u)->getJson('/api/notifications')->json('notifications.*.data.type');
    }

    protected function messagesNotifications(Utilisateur $u): array
    {
        return $this->en($u)->getJson('/api/notifications')->json('notifications.*.data.message');
    }

    /** Physique et réservé du stock. */
    protected function niveaux(?Stock $stock = null): array
    {
        $s = ($stock ?? $this->stock)->fresh();

        return [(float) $s->quantite, (float) $s->quantite_reservee];
    }
}
