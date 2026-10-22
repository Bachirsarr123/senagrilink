<?php

namespace App\Support;

use App\Models\Entrepot;

/**
 * Montants d'une commande (lot 12), figés au moment où elle est passée :
 * l'acheteur paie la marchandise (prix fixé par le producteur) et la livraison ;
 * le producteur reçoit la marchandise moins les frais d'entrepôt et la commission.
 */
final class Tarifs
{
    /** @return array{prix: float, frais_livraison: float, frais_stockage: float, commission: float, montant_producteur: float, total_a_payer: float, distance_km: ?float} */
    public static function calculer(float $prixMarchandise, float $quantite, ?Entrepot $e, ?float $lat, ?float $lng): array
    {
        $km = $e?->latitude !== null && $e?->longitude !== null && $lat !== null && $lng !== null
            ? round(SuiviGps::distanceKm((float) $e->latitude, (float) $e->longitude, $lat, $lng) * \App\Services\EstimationTrajet::FACTEUR_ROUTE, 1)
            : null;

        // Livraison : forfait + distance par la route, arrondie à 50 FCFA près
        $livraison  = self::arrondir(config('services.paiement.livraison_forfait', 500) + config('services.paiement.livraison_par_km', 100) * ($km ?? 0), 50);
        $commission = round($prixMarchandise * config('services.paiement.commission_pourcent', 2) / 100);
        $stockage   = round((float) ($e?->frais_stockage_kg ?? 0) * $quantite);

        return [
            'prix'               => round($prixMarchandise, 2),
            'frais_livraison'    => $livraison,
            'frais_stockage'     => $stockage,
            'commission'         => $commission,
            'montant_producteur' => max(0, round($prixMarchandise - $commission - $stockage, 2)),
            'total_a_payer'      => round($prixMarchandise + $livraison, 2),
            'distance_km'        => $km,
        ];
    }

    private static function arrondir(float $v, int $pas): float
    {
        return (float) (ceil($v / $pas) * $pas);
    }
}
