<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrepot extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'nom_entrepot', 'capacite', 'localisation', 'latitude', 'longitude'];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'capacite'  => 'float',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

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
