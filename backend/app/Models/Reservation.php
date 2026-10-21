<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Reservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_reservation',
        'entrepot_id',
        'producteur_id',
        'production_id',
        'transporteur_id',
        'produit',
        'quantite_reservee',
        'date_debut',
        'date_fin',
        'date_reservation',
        'statut',
        'mode_transport',
        'mission_acceptee_le',
        'quantite_recue',
        'etat_marchandise',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'quantite_reservee'   => 'float',
        'quantite_recue'      => 'float',
        'mission_acceptee_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            if (empty($reservation->numero_reservation)) {
                $reservation->numero_reservation = 'RES-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function entrepot()
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id');
    }

    public function producteur()
    {
        return $this->belongsTo(Producteur::class, 'producteur_id');
    }

    public function production()
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'sujet_id')->where('sujet_type', 'reservation')->orderBy('id');
    }

    /** Étapes, corrections et annulations successives (plus ancienne d'abord). */
    public function historique()
    {
        return $this->hasMany(HistoriqueOperation::class, 'sujet_id')
            ->where('sujet_type', 'reservation')
            ->orderBy('id');
    }
}
