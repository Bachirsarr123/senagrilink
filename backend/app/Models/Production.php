<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Production extends Model
{
    /** Statuts connus : en_attente (créée), disponible (réceptionnée en entrepôt). */
    public const STATUTS = ['en_attente', 'disponible'];

    protected $fillable = [
        'producteur_id',
        'code_tracabilite',
        'type_culture',
        'superficie',
        'date_recolte',
        'quantite_estimee',
        'quantite_reelle',
        'statut',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    protected $casts = [
        'superficie'       => 'float',
        'quantite_estimee' => 'float',
        'quantite_reelle'  => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Production $production) {
            if (empty($production->code_tracabilite)) {
                $production->code_tracabilite = 'LOT-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    public function producteur()
    {
        return $this->belongsTo(Producteur::class, 'producteur_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'production_id');
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class, 'production_id');
    }

    /** Corrections et annulations successives (plus ancienne d'abord). */
    public function historique()
    {
        return $this->hasMany(HistoriqueOperation::class, 'sujet_id')
            ->where('sujet_type', 'production')
            ->orderBy('id');
    }
}
