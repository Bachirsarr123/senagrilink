<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entrepot_id',
        'production_id',
        'produit',
        'quantite',
        'date_entree',
        'date_sortie',
        'seuil_alerte',
        'observation',
        'statut',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'quantite'     => 'float',
        'seuil_alerte' => 'float',
    ];

    public function entrepot()
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id');
    }

    public function production()
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'stock_id');
    }
}
