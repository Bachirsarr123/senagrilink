<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ligne du journal des mouvements d'un stock : chaque variation du stock
 * physique ou réservé en laisse une, avec son auteur et l'opération d'origine.
 * Types : ouverture | entree | reservation | liberation | sortie | retour | ajustement.
 */
class MouvementStock extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'mouvements_stock';

    protected $fillable = [
        'stock_id', 'type', 'motif_type', 'variation_physique', 'variation_reservee',
        'physique_apres', 'reservee_apres', 'operation_type', 'operation_id', 'motif', 'utilisateur_id',
    ];

    protected $casts = [
        'variation_physique' => 'float',
        'variation_reservee' => 'float',
        'physique_apres'     => 'float',
        'reservee_apres'     => 'float',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }
}
