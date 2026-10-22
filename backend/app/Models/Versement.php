<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Part d'une commande revenant à un acteur : producteur, entrepôt, transporteur ou SenAgriLink. */
class Versement extends Model
{
    public const BENEFICIAIRES = ['producteur', 'entrepot', 'transporteur', 'plateforme'];

    protected $fillable = ['commande_id', 'paiement_id', 'beneficiaire', 'utilisateur_id', 'montant', 'statut', 'moyen', 'telephone', 'reference_externe', 'verse_le'];

    protected $casts = ['montant' => 'float', 'verse_le' => 'datetime'];

    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class);
    }
}
