<?php

namespace App\Support;

use App\Models\Reservation;
use App\Models\Utilisateur;

/**
 * Définition unique du circuit « récolte → entrepôt » (cahier des charges v2, §4).
 *
 *   en_attente ─confirmer→ confirmee ─────────────────────────────┐ (mode producteur)
 *        │                    │ transporteur affecté puis accepté   │
 *     refuser            prise_en_charge → au_point_collecte → chargee
 *        ↓                                                   │      │
 *     refusee                                            en_route ←┘
 *                                                           ↓
 *                                                   arrivee_entrepot
 *                                                    ↙            ↘
 *                                           enregistree     reception_refusee
 *
 * Le producteur peut annuler avant le départ (en_route). Les clients affichent
 * les boutons à partir de actions() : aucune règle n'est recopiée côté site ou appli.
 */
final class CircuitDepot
{
    public const MODE_TRANSPORTEUR = 'transporteur';
    public const MODE_PRODUCTEUR   = 'producteur';
    public const MODES             = [self::MODE_TRANSPORTEUR, self::MODE_PRODUCTEUR];

    /** Statuts qui occupent de la place dans l'entrepôt (capacité) et engagent une partie du lot. */
    public const STATUTS_ACTIFS = ['en_attente', 'confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot'];

    /** Le producteur peut annuler jusqu'au départ (étape 7). */
    public const ANNULABLES = ['en_attente', 'confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee'];

    /** Le gestionnaire peut affecter, réaffecter ou retirer le transporteur avant l'arrivée chez le producteur. */
    public const TRANSPORTEUR_MODIFIABLE = ['confirmee', 'prise_en_charge'];

    /** Pendant l'acheminement, la personne qui transporte peut déclarer un incident. */
    public const INCIDENT_POSSIBLE = ['prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'];

    /** Étapes de transport : étape => statuts de départ possibles, par mode. */
    public const ETAPES = [
        'au_point_collecte' => [self::MODE_TRANSPORTEUR => ['prise_en_charge']],
        'chargee'           => [self::MODE_TRANSPORTEUR => ['au_point_collecte']],
        'en_route'          => [self::MODE_TRANSPORTEUR => ['chargee'], self::MODE_PRODUCTEUR => ['confirmee']],
        'arrivee_entrepot'  => [self::MODE_TRANSPORTEUR => ['en_route'], self::MODE_PRODUCTEUR => ['en_route']],
    ];

    /** Action à afficher pour chaque étape (même libellé sur le site et l'appli). */
    private const ACTION_ETAPE = [
        'au_point_collecte' => 'arriver_collecte',
        'chargee'           => 'charger',
        'en_route'          => 'partir',
        'arrivee_entrepot'  => 'arriver',
    ];

    /** Statut de départ autorisé pour une étape, selon le mode ; null si l'étape n'existe pas dans ce mode. */
    public static function depuis(string $etape, string $mode): ?array
    {
        return self::ETAPES[$etape][$mode] ?? null;
    }

    /** Conducteur de la marchandise : celui qui franchit les étapes de transport. */
    public static function estConducteur(Reservation $r, Utilisateur $u): bool
    {
        return $r->mode_transport === self::MODE_PRODUCTEUR
            ? $u->producteur !== null && (int) $u->producteur->id === (int) $r->producteur_id
            : $u->transporteur !== null && (int) $u->transporteur->id === (int) $r->transporteur_id
                && $r->mission_acceptee_le !== null;
    }

    /**
     * Actions possibles pour cet utilisateur sur cette réservation, dans l'ordre
     * d'affichage. L'administrateur dispose des actions du gestionnaire.
     */
    public static function actions(Reservation $r, Utilisateur $u, bool $incidentBloquant = false): array
    {
        $actions = [];
        $statut  = $r->statut;
        $modeT   = $r->mode_transport !== self::MODE_PRODUCTEUR;

        $gestionnaire = $u->role === 'administrateur'
            || ($u->entrepot !== null && (int) $u->entrepot->id === (int) $r->entrepot_id);
        $producteur   = $u->producteur !== null && (int) $u->producteur->id === (int) $r->producteur_id;
        $transporteur = $u->transporteur !== null && (int) $u->transporteur->id === (int) $r->transporteur_id;

        if ($gestionnaire) {
            if ($statut === 'en_attente') {
                array_push($actions, 'confirmer', 'refuser');
            }
            if ($statut === 'confirmee' && ! $r->transporteur_id) {
                $actions[] = 'remettre_en_attente';
            }
            if ($modeT && in_array($statut, self::TRANSPORTEUR_MODIFIABLE, true)) {
                $actions[] = 'assigner';
                if ($r->transporteur_id) {
                    $actions[] = 'retirer_transporteur';
                }
            }
            if ($statut === 'arrivee_entrepot') {
                array_push($actions, 'receptionner', 'refuser_reception');
            }
        }

        if ($transporteur && $modeT && $statut === 'confirmee' && $r->mission_acceptee_le === null) {
            array_push($actions, 'accepter_mission', 'refuser_mission');
        }

        if (self::estConducteur($r, $u)) {
            foreach (self::ETAPES as $etape => $parMode) {
                if (! $incidentBloquant && in_array($statut, $parMode[$r->mode_transport] ?? [], true)) {
                    $actions[] = self::ACTION_ETAPE[$etape];
                }
            }
            if (in_array($statut, self::INCIDENT_POSSIBLE, true)) {
                $actions[] = 'declarer_incident';
            }
        }

        if ($producteur && in_array($statut, self::ANNULABLES, true)) {
            $actions[] = 'annuler';
        }

        return $actions;
    }

    /** Étape suivante attendue, en clair, et qui doit agir (affichage « prochaine action »). */
    public static function prochaineEtape(Reservation $r): ?array
    {
        $modeT = $r->mode_transport !== self::MODE_PRODUCTEUR;

        return match ($r->statut) {
            'en_attente'        => ['libelle' => 'Demande à examiner', 'acteur' => 'gestionnaire_entrepot'],
            'confirmee'         => match (true) {
                ! $modeT                          => ['libelle' => 'Le producteur apporte la marchandise', 'acteur' => 'producteur'],
                ! $r->transporteur_id             => ['libelle' => 'Transporteur à affecter', 'acteur' => 'gestionnaire_entrepot'],
                default                           => ['libelle' => 'Mission à accepter par le transporteur', 'acteur' => 'transporteur'],
            },
            'prise_en_charge'   => ['libelle' => 'Le transporteur se rend chez le producteur', 'acteur' => 'transporteur'],
            'au_point_collecte' => ['libelle' => 'Chargement de la marchandise', 'acteur' => 'transporteur'],
            'chargee'           => ['libelle' => 'Départ vers l\'entrepôt', 'acteur' => 'transporteur'],
            'en_route'          => ['libelle' => 'En route vers l\'entrepôt', 'acteur' => $modeT ? 'transporteur' : 'producteur'],
            'arrivee_entrepot'  => ['libelle' => 'Réception à contrôler', 'acteur' => 'gestionnaire_entrepot'],
            default             => null,
        };
    }
}
