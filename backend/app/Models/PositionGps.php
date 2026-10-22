<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PositionGps extends Model
{
    public $timestamps = false;

    protected $table = 'positions_gps';

    protected $fillable = [
        'transporteur_id', 'utilisateur_id', 'sujet_type', 'sujet_id', 'latitude', 'longitude', 'timestamp',
        'precision_m', 'vitesse_kmh', 'cap', 'recue_le',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
            'timestamp' => 'datetime',
            'recue_le'  => 'datetime',
            'precision_m' => 'float',
            'vitesse_kmh' => 'float',
            'cap'         => 'float',
        ];
    }

    /** Supprime les positions plus anciennes que la durée de conservation (30 jours par défaut, Q11). */
    public static function purger(?int $jours = null): int
    {
        return static::where('timestamp', '<', now()->subDays($jours ?? config('services.suivi.conservation_jours', 30)))->delete();
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }
}
