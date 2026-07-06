<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Reservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_reservation',
        'entrepot_id',
        'producteur_id',
        'production_id',
        'transporteur_id',
        'produit',
        'quantite_reservee',
        'date_debut',
        'date_fin',
        'date_reservation',
        'statut',
    ];

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            if (empty($reservation->numero_reservation)) {
                $reservation->numero_reservation = 'RES-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function entrepot()
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id');
    }

    public function producteur()
    {
        return $this->belongsTo(Producteur::class, 'producteur_id');
    }

    public function production()
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }
}
