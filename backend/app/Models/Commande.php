<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Commande extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_commande',
        'acheteur_id',
        'stock_id',
        'produit',
        'quantite',
        'prix',
        'date_commande',
        'statut',
    ];

    protected static function booted(): void
    {
        static::creating(function (Commande $commande) {
            if (empty($commande->numero_commande)) {
                $commande->numero_commande = 'CMD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function acheteur()
    {
        return $this->belongsTo(AcheteurGros::class, 'acheteur_id');
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function livraison()
    {
        return $this->hasOne(Livraison::class, 'commande_id');
    }
}
