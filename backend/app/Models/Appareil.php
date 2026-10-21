<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Téléphone d'un utilisateur, joignable par notification Firebase (lot 6, Q15). */
class Appareil extends Model
{
    protected $fillable = ['utilisateur_id', 'jeton', 'plateforme', 'derniere_activite'];

    protected $casts = ['derniere_activite' => 'datetime'];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }
}
