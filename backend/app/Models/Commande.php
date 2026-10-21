<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Commande extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'numero_commande',
        'acheteur_id',
        'stock_id',
        'produit',
        'quantite',
        'prix',
        'prix_unitaire',
        'etat_stock',
        'panier',
        'code_reception',
        'date_commande',
        'statut',
    ];

    /**
     * Nombres renvoyés comme tels en JSON : sous PostgreSQL, les colonnes
     * float/decimal arrivent en chaînes (« 12.5 ») et cassaient les clients (P-07).
     */
    /** Le code de réception n'est donné qu'à l'acheteur (ajouté explicitement à son historique). */
    protected $hidden = ['code_reception'];

    protected $casts = [
        'quantite' => 'float',
        'prix'          => 'float',
        'prix_unitaire' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Commande $commande) {
            if (empty($commande->numero_commande)) {
                $commande->numero_commande = 'CMD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function acheteur()
    {
        return $this->belongsTo(AcheteurGros::class, 'acheteur_id');
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    /** Livraison active (la plus récente : une livraison annulée peut être remplacée). */
    public function livraison()
    {
        return $this->hasOne(Livraison::class, 'commande_id')->orderByDesc('id');
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class, 'sujet_id')->where('sujet_type', 'commande')->orderBy('id');
    }

    /**
     * Périmètre de gestion (même règle que ReservationController::reservationDeLEntrepot) :
     * l'administrateur gère toutes les commandes, le gestionnaire uniquement celles
     * qui portent sur un stock de son entrepôt. Sans stock, le périmètre ne peut
     * pas être établi : refus pour un gestionnaire.
     */
    public function estGereePar(Utilisateur $utilisateur): bool
    {
        if ($utilisateur->role === 'administrateur') {
            return true;
        }

        $entrepotId = $utilisateur->entrepot?->id;

        return $entrepotId !== null
            && $this->stock !== null
            && (int) $this->stock->entrepot_id === (int) $entrepotId;
    }

    /** Corrections et annulations successives (plus ancienne d'abord). */
    public function historique()
    {
        return $this->hasMany(HistoriqueOperation::class, 'sujet_id')
            ->where('sujet_type', 'commande')
            ->orderBy('id');
    }
}
