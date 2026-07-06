<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrepot extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'nom_entrepot', 'capacite', 'localisation'];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class, 'entrepot_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'entrepot_id');
    }
}
