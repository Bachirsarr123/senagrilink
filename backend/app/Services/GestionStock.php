<?php

namespace App\Services;

use App\Exceptions\StockInsuffisantException;
use App\Models\MouvementStock;
use App\Models\Stock;
use App\Models\Utilisateur;

/**
 * Seul point d'écriture des quantités d'un stock. Chaque méthode verrouille
 * la ligne (FOR UPDATE), revérifie, applique la variation, recalcule le statut
 * et consigne un mouvement. Elle doit être appelée dans une transaction.
 *
 * Invariant garanti : 0 <= quantite_reservee <= quantite (physique).
 * Le disponible (physique - réservé) est le seul chiffre proposé aux acheteurs.
 */
class GestionStock
{
    /** Motifs d'ajustement manuel autorisés (décision Q14). */
    public const MOTIFS_AJUSTEMENT = ['perte', 'avarie', 'retrait_producteur', 'correction_inventaire'];

    /** Entrée initiale d'un lot réceptionné : crée la ligne de stock et son mouvement. */
    public function entrer(array $attributs, ?Utilisateur $auteur, ?string $operationType = null, ?int $operationId = null): Stock
    {
        $stock = Stock::create(array_merge($attributs, [
            'quantite_reservee' => 0,
            'statut'            => 'disponible',
        ]));

        $this->consigner($stock, 'entree', (float) $stock->quantite, 0, $auteur, $operationType, $operationId);

        return $stock;
    }

    /** Réserve une quantité pour une commande confirmée (le physique ne bouge pas). */
    public function reserver(int $stockId, float $quantite, ?Utilisateur $auteur, string $operationType, int $operationId): Stock
    {
        $stock = $this->verrouiller($stockId);

        if ($stock->statut !== 'disponible' || $quantite > $stock->quantite_disponible + 1e-9) {
            throw new StockInsuffisantException('Stock disponible insuffisant.', $stock->quantite_disponible, $quantite);
        }

        $stock->quantite_reservee = round($stock->quantite_reservee + $quantite, 3);
        $stock->save();

        return $this->consigner($stock, 'reservation', 0, $quantite, $auteur, $operationType, $operationId);
    }

    /** Rend une quantité réservée (annulation avant le départ). */
    public function liberer(int $stockId, float $quantite, ?Utilisateur $auteur, string $operationType, int $operationId, ?string $motif = null): Stock
    {
        $stock = $this->verrouiller($stockId);
        $quantite = min($quantite, (float) $stock->quantite_reservee);

        $stock->quantite_reservee = round($stock->quantite_reservee - $quantite, 3);
        $stock->save();

        return $this->consigner($stock, 'liberation', 0, -$quantite, $auteur, $operationType, $operationId, $motif);
    }

    /** Sortie physique d'une quantité préalablement réservée (départ de la livraison). */
    public function sortirReserve(int $stockId, float $quantite, ?Utilisateur $auteur, string $operationType, int $operationId): Stock
    {
        $stock = $this->verrouiller($stockId);

        if ($quantite > $stock->quantite_reservee + 1e-9 || $quantite > $stock->quantite + 1e-9) {
            throw new StockInsuffisantException('La quantité à sortir n\'est pas réservée sur ce stock.', (float) $stock->quantite_reservee, $quantite);
        }

        $stock->quantite          = round($stock->quantite - $quantite, 3);
        $stock->quantite_reservee = round($stock->quantite_reservee - $quantite, 3);
        $this->majStatut($stock);
        $stock->save();

        return $this->consigner($stock, 'sortie', -$quantite, -$quantite, $auteur, $operationType, $operationId);
    }

    /** Retour à l'entrepôt d'une quantité sortie (livraison interrompue ou refusée). */
    public function retourner(int $stockId, float $quantite, ?Utilisateur $auteur, string $operationType, int $operationId, ?string $motif = null): Stock
    {
        $stock = $this->verrouiller($stockId);

        $stock->quantite = round($stock->quantite + $quantite, 3);
        $this->majStatut($stock);
        $stock->save();

        return $this->consigner($stock, 'retour', $quantite, 0, $auteur, $operationType, $operationId, $motif);
    }

    /**
     * Ajustement manuel motivé. Une baisse ne peut entamer que le disponible
     * (jamais une quantité promise à une commande) ; seule une correction
     * d'inventaire peut augmenter le stock.
     */
    public function ajuster(int $stockId, string $motifType, float $variation, string $motif, ?Utilisateur $auteur): Stock
    {
        $stock = $this->verrouiller($stockId);

        if ($variation < 0 && -$variation > $stock->quantite_disponible + 1e-9) {
            throw new StockInsuffisantException(
                'Quantité disponible insuffisante : une quantité réservée pour une commande ne peut pas être retirée.',
                $stock->quantite_disponible,
                -$variation,
            );
        }

        $stock->quantite = round($stock->quantite + $variation, 3);
        $this->majStatut($stock);
        $stock->save();

        return $this->consigner($stock, 'ajustement', $variation, 0, $auteur, null, null, $motif, $motifType);
    }

    private function verrouiller(int $stockId): Stock
    {
        return Stock::whereKey($stockId)->lockForUpdate()->firstOrFail();
    }

    private function majStatut(Stock $stock): void
    {
        if ($stock->quantite <= 0) {
            $stock->quantite    = 0;
            $stock->statut      = 'epuise';
            $stock->date_sortie = now()->toDateString();
        } elseif ($stock->statut === 'epuise') {
            $stock->statut      = 'disponible';
            $stock->date_sortie = null;
        }
    }

    private function consigner(
        Stock $stock,
        string $type,
        float $variationPhysique,
        float $variationReservee,
        ?Utilisateur $auteur,
        ?string $operationType,
        ?int $operationId,
        ?string $motif = null,
        ?string $motifType = null,
    ): Stock {
        MouvementStock::create([
            'stock_id'           => $stock->id,
            'type'               => $type,
            'motif_type'         => $motifType,
            'variation_physique' => $variationPhysique,
            'variation_reservee' => $variationReservee,
            'physique_apres'     => (float) $stock->quantite,
            'reservee_apres'     => (float) $stock->quantite_reservee,
            'operation_type'     => $operationType,
            'operation_id'       => $operationId,
            'motif'              => $motif,
            'utilisateur_id'     => $auteur?->id,
        ]);

        return $stock;
    }
}
