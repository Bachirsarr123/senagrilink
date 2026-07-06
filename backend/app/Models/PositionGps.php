<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PositionGps extends Model
{
    public $timestamps = false;

    protected $table = 'positions_gps';

    protected $fillable = ['transporteur_id', 'latitude', 'longitude', 'timestamp'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
            'timestamp' => 'datetime',
        ];
    }

    public function transporteur()
    {
        return $this->belongsTo(Transporteur::class, 'transporteur_id');
    }
}
