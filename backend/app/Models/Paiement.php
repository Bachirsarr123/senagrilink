<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Paiement de l'acheteur (une ou plusieurs commandes d'un même panier). */
class Paiement extends Model
{
    protected $fillable = ['reference', 'acheteur_id', 'montant', 'moyen', 'fournisseur', 'statut', 'reference_externe', 'telephone', 'paye_le', 'rembourse_le'];

    protected $casts = ['montant' => 'float', 'paye_le' => 'datetime', 'rembourse_le' => 'datetime'];

    public function commandes()
    {
        return $this->hasMany(Commande::class, 'paiement_id');
    }

    public function acheteur()
    {
        return $this->belongsTo(AcheteurGros::class, 'acheteur_id');
    }
}
