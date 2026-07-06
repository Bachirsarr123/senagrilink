<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Livraison extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_livraison',
        'commande_id',
        'transporteur_id',
        'origine',
        'destination',
        'destination_latitude',
        'destination_longitude',
        'date_depart',
        'date_livraison',
        'statut',
    ];

    protected static function booted(): void
    {
        static::creating(function (Livraison $livraison) {
            if (empty($livraison->numero_livraison)) {
                $livraison->numero_livraison = 'LIV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function commande()
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }
}
