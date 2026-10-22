<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Fin de livraison : la livraison arrivée est remise à l'acheteur. Soit
 * l'acheteur confirme lui-même, soit le transporteur saisit le code de
 * réception que l'acheteur lui donne. À appeler dans une transaction, commande
 * et livraison verrouillées.
 */
class ReceptionCommande
{
    public function receptionner(Commande $c, Livraison $l, ?Utilisateur $auteur, string $mode): void
    {
        $l->statut         = 'livree';
        $l->date_livraison = now();
        $l->save();
        HistoriqueOperation::consigner('livraison', $l->id, 'livraison', $auteur, 'arrivee', 'livree', null, ['mode' => $mode]);

        $c->statut = 'receptionnee';
        $c->save();
        HistoriqueOperation::consigner('commande', $c->id, 'reception', $auteur, 'en_livraison', 'receptionnee', null, ['mode' => $mode]);

        // Lot 12 : la marchandise est reçue, l'argent mis de côté est versé à chacun
        DB::afterCommit(fn () => app(Paiements::class)->liberer($c));
    }
}
