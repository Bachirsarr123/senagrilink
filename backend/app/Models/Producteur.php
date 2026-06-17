<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producteur extends Model
{
    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'superficie', 'types_cultures', 'region'];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function productions()
    {
        return $this->hasMany(Production::class, 'producteur_id');
    }
}
