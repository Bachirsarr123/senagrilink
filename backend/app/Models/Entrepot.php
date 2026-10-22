<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrepot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'utilisateur_id', 'nom_entrepot', 'capacite', 'localisation', 'latitude', 'longitude',
        // Lot 11 : pilote automatique
        'auto_accepter_depots', 'auto_confirmer_commandes', 'auto_affecter_transporteurs', 'delai_reponse_min',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'capacite'  => 'float',
        'latitude'  => 'float',
        'longitude' => 'float',
        'auto_accepter_depots'        => 'boolean',
        'auto_confirmer_commandes'    => 'boolean',
        'auto_affecter_transporteurs' => 'boolean',
        'delai_reponse_min'           => 'integer',
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
