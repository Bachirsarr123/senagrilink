<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot 13 : commandes passées avant les paiements dans l'application (montant
 * à payer jamais calculé). Elles étaient réglées en dehors de l'application :
 * elles ne sont pas « à payer », sinon l'entrepôt ne pourrait plus les traiter.
 *  - en cours : réglées à la livraison, comme avant ;
 *  - reçues : réglées ; annulées ou refusées : sans paiement.
 * Aucune commande créée depuis les paiements (montant calculé) n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $anciennes = fn () => DB::table('commandes')->where('statut_paiement', 'a_payer')->whereNull('paiement_id')
            ->where(fn ($q) => $q->whereNull('total_a_payer')->orWhere('total_a_payer', 0));

        $anciennes()->where('statut', 'receptionnee')->update(['statut_paiement' => 'encaisse']);
        $anciennes()->whereIn('statut', ['annulee', 'refusee'])->update(['statut_paiement' => 'annule']);
        $anciennes()->update(['statut_paiement' => 'a_la_livraison']);
    }

    public function down(): void
    {
        DB::table('commandes')->whereNull('paiement_id')->where(fn ($q) => $q->whereNull('total_a_payer')->orWhere('total_a_payer', 0))
            ->whereIn('statut_paiement', ['encaisse', 'annule', 'a_la_livraison'])->update(['statut_paiement' => 'a_payer']);
    }
};
