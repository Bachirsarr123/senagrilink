<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcheteurGros extends Model
{
    protected $table = 'acheteurs_gros';

    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'type_activite', 'volume_achat_mensuel'];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'acheteur_id');
    }
}
