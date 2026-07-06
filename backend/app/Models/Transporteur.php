<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transporteur extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'type_vehicule', 'capacite_charge', 'zone'];

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
