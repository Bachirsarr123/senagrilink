<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entrepot_id',
        'production_id',
        'produit',
        'quantite',
        'quantite_reservee',
        'date_entree',
        'date_sortie',
        'seuil_alerte',
        'observation',
        'statut',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'quantite'          => 'float',
        'quantite_reservee' => 'float',
        'seuil_alerte'      => 'float',
    ];

    /** « quantite » est le stock physique ; le disponible en retire le réservé. */
    protected $appends = ['quantite_disponible'];

    public function getQuantiteDisponibleAttribute(): ?float
    {
        // Ligne chargée partiellement (select de colonnes) : disponible inconnu.
        if (! array_key_exists('quantite', $this->attributes) || ! array_key_exists('quantite_reservee', $this->attributes)) {
            return null;
        }

        return max(0.0, round((float) $this->quantite - (float) $this->quantite_reservee, 3));
    }

    public function entrepot()
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id');
    }

    public function production()
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'stock_id');
    }

    public function mouvements()
    {
        return $this->hasMany(MouvementStock::class, 'stock_id')->orderBy('id');
    }
}
