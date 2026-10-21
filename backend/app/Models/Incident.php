<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Incident sur une opération (cahier des charges v2, §5.2) : déclaré par un
 * acteur, il désigne qui décide ensuite et garde la décision prise.
 * sujet_type : livraison | reservation | commande.
 */
class Incident extends Model
{
    /** Types déclarables, par sujet. */
    public const TYPES = [
        'livraison'   => ['panne', 'retard', 'acces_impossible', 'acheteur_absent', 'marchandise_abimee', 'autre'],
        'reservation' => ['panne', 'retard', 'acces_impossible', 'marchandise_abimee', 'autre'],
        'commande'    => ['demande_annulation', 'quantite_manquante', 'produit_abime', 'livraison_incorrecte'],
    ];

    /** Anomalies déclarées par l'acheteur à la réception. */
    public const ANOMALIES = ['quantite_manquante', 'produit_abime', 'livraison_incorrecte'];

    /** Incidents qui arrêtent le transport jusqu'à la décision de l'entrepôt (un retard, non). */
    public const BLOQUANTS = ['panne', 'acces_impossible', 'acheteur_absent', 'marchandise_abimee'];

    /** Décisions possibles, par sujet. */
    public const DECISIONS = [
        'livraison'   => ['reprendre', 'changer_transporteur', 'retour_entrepot', 'annuler_commande'],
        'reservation' => ['reprendre', 'changer_transporteur', 'annuler_depot'],
        'commande'    => ['accepter_annulation', 'refuser_annulation', 'cloturer_litige', 'reprendre_marchandise'],
    ];

    protected $fillable = [
        'sujet_type', 'sujet_id', 'type', 'description', 'statut', 'declare_par',
        'decision', 'motif_decision', 'resolu_par', 'resolu_le',
    ];

    protected $casts = ['resolu_le' => 'datetime'];

    public function declarant()
    {
        return $this->belongsTo(Utilisateur::class, 'declare_par');
    }

    public function resolveur()
    {
        return $this->belongsTo(Utilisateur::class, 'resolu_par');
    }

    public function scopeOuverts($q)
    {
        return $q->where('statut', 'ouvert');
    }

    public function estBloquant(): bool
    {
        return $this->statut === 'ouvert' && in_array($this->type, self::BLOQUANTS, true);
    }

    /** Un incident bloquant est-il ouvert sur ce sujet ? */
    public static function bloque(string $sujetType, int $sujetId): bool
    {
        return self::ouverts()->where('sujet_type', $sujetType)->where('sujet_id', $sujetId)
            ->whereIn('type', self::BLOQUANTS)->exists();
    }
}
