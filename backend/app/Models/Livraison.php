<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Livraison extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_livraison',
        'commande_id',
        'transporteur_id',
        'origine',
        'destination',
        'destination_latitude',
        'destination_longitude',
        'date_depart',
        'date_livraison',
        'statut',
        'mission_acceptee_le',
        'parti_le',
        'arrive_le',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'destination_latitude'  => 'float',
        'destination_longitude' => 'float',
        'mission_acceptee_le'   => 'datetime',
        'parti_le'              => 'datetime',
        'arrive_le'             => 'datetime',
    ];

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'sujet_id')->where('sujet_type', 'livraison')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::creating(function (Livraison $livraison) {
            if (empty($livraison->numero_livraison)) {
                $livraison->numero_livraison = 'LIV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function commande()
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }

    /** Corrections et annulations successives (plus ancienne d'abord). */
    public function historique()
    {
        return $this->hasMany(HistoriqueOperation::class, 'sujet_id')
            ->where('sujet_type', 'livraison')
            ->orderBy('id');
    }
}
