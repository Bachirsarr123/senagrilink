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
        'statut',
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
