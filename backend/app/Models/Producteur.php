<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producteur extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'superficie', 'types_cultures', 'region'];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'superficie' => 'float',
    ];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function productions()
    {
        return $this->hasMany(Production::class, 'producteur_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'producteur_id');
    }
}
