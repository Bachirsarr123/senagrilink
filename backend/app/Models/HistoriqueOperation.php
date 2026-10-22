<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entrée d'historique d'une opération métier : chaque étape franchie, chaque
 * correction ou annulation (qui, quand, quelle transition, pourquoi).
 * sujet_type : reservation | commande | livraison | production | utilisateur.
 */
class HistoriqueOperation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historique_operations';

    protected $fillable = [
        'sujet_type', 'sujet_id', 'action', 'ancien_statut', 'nouveau_statut',
        'details', 'motif', 'utilisateur_id', 'adresse_ip',
    ];

    protected $casts = ['details' => 'array'];

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public static function consigner(
        string $sujetType,
        int $sujetId,
        string $action,
        ?Utilisateur $auteur,
        ?string $ancienStatut = null,
        ?string $nouveauStatut = null,
        ?string $motif = null,
        array $details = [],
    ): self {
        return self::create([
            'sujet_type'     => $sujetType,
            'sujet_id'       => $sujetId,
            'action'         => $action,
            'ancien_statut'  => $ancienStatut,
            'nouveau_statut' => $nouveauStatut,
            'motif'          => $motif,
            // Lot 11 : action faite par le pilote automatique, au nom du gestionnaire
            'details'        => (\App\Services\PiloteAutomatique::$enCours ? [...$details, 'automatique' => true] : $details) ?: null,
            'utilisateur_id' => $auteur?->id,
            // Journal d'audit (lot 6) : adresse de la requête, hors console et tests unitaires.
            'adresse_ip'     => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()?->ip(),
        ]);
    }
}
