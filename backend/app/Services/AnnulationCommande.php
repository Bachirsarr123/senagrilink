<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\HistoriqueOperation;
use App\Models\Livraison;
use App\Models\Utilisateur;
use App\Support\CircuitLivraison;

/**
 * Annulation d'une commande par l'entrepôt, quel que soit son avancement :
 *  - avant le chargement, la réservation est libérée et la livraison annulée ;
 *  - après le chargement (retour à l'entrepôt), la quantité sortie revient au stock.
 * À appeler dans une transaction, commande verrouillée.
 */
class AnnulationCommande
{
    public function __construct(private readonly GestionStock $gestionStock)
    {
    }

    /** Renvoie l'identifiant du transporteur à prévenir (mission retirée), ou null. */
    public function annuler(Commande $c, ?Utilisateur $auteur, string $action, ?string $motif, bool $retourEntrepot = false): ?int
    {
        $l = Livraison::where('commande_id', $c->id)->whereNotIn('statut', ['annulee', 'retournee', 'livree'])->lockForUpdate()->first();
        $transporteur = $l?->transporteur_id;

        if ($l) {
            $ancien    = $l->statut;
            $l->statut = $retourEntrepot && ! in_array($ancien, CircuitLivraison::TRANSPORTEUR_MODIFIABLE, true) ? 'retournee' : 'annulee';
            $l->save();
            HistoriqueOperation::consigner('livraison', $l->id, $l->statut === 'retournee' ? 'retour_entrepot' : 'annulation', $auteur, $ancien, $l->statut, $motif);
        }

        if ($c->stock_id && $c->etat_stock === 'reserve') {
            $this->gestionStock->liberer($c->stock_id, (float) $c->quantite, $auteur, 'commande', $c->id, $motif);
            $c->etat_stock = 'libere';
        } elseif ($c->stock_id && $c->etat_stock === 'sorti') {
            $this->gestionStock->retourner($c->stock_id, (float) $c->quantite, $auteur, 'commande', $c->id, $motif);
            $c->etat_stock = 'retourne';
        }

        $ancienStatut = $c->statut;
        $c->statut    = 'annulee';
        $c->save();
        HistoriqueOperation::consigner('commande', $c->id, $action, $auteur, $ancienStatut, 'annulee', $motif, [
            'quantite' => (float) $c->quantite, 'etat_stock' => $c->etat_stock,
        ]);

        return $transporteur;
    }
}
