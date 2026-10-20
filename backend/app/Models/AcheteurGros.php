<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcheteurGros extends Model
{
    protected $table = 'acheteurs_gros';

    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'type_activite', 'volume_achat_mensuel'];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'volume_achat_mensuel' => 'float',
    ];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'acheteur_id');
    }
}
