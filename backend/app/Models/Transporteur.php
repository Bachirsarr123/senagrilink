<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transporteur extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'type_vehicule', 'capacite_charge', 'zone'];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'capacite_charge' => 'float',
    ];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function livraisons()
    {
        return $this->hasMany(Livraison::class, 'transporteur_id');
    }

    public function positionsGps()
    {
        return $this->hasMany(PositionGps::class, 'transporteur_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'transporteur_id');
    }
}
