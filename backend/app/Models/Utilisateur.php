<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Utilisateur extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'utilisateurs';

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'mot_de_passe',
        'photo',
        'role',
        'statut',
    ];

    protected $hidden = [
        'mot_de_passe',
    ];

    protected function casts(): array
    {
        return [
            'mot_de_passe' => 'hashed',
        ];
    }

    // Laravel utilise "password" par défaut — on redirige vers mot_de_passe
    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    // Relations vers les profils spécifiques
    public function producteur()
    {
        return $this->hasOne(Producteur::class, 'utilisateur_id');
    }

    public function entrepot()
    {
        return $this->hasOne(Entrepot::class, 'utilisateur_id');
    }

    public function acheteurGros()
    {
        return $this->hasOne(AcheteurGros::class, 'utilisateur_id');
    }

    public function transporteur()
    {
        return $this->hasOne(Transporteur::class, 'utilisateur_id');
    }
}
