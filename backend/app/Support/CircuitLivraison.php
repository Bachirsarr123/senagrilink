<?php

namespace App\Support;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Utilisateur;

/**
 * Définition unique du circuit « entrepôt → acheteur » (cahier des charges v2, §5).
 *
 * Commande : en_attente → confirmee (réserve) → en_preparation → prete
 *            → en_livraison (chargée : sortie du stock) → receptionnee | litige ;
 *            annulee à tout moment avant le chargement (puis par retour à l'entrepôt).
 * Livraison : en_attente (mission proposée) → prise_en_charge → chargee → en_cours
 *            (en route, GPS) → arrivee → livree ; annulee, retournee.
 *
 * Les clients affichent les boutons à partir de actions() : aucune règle n'est
 * recopiée côté site ou application.
 */
final class CircuitLivraison
{
    /** Avant le chargement, la commande peut être annulée (la réservation est libérée). */
    public const ANNULABLES_AVANT_CHARGEMENT = ['confirmee', 'en_preparation', 'prete'];

    /** Livraison dont le transporteur peut encore être changé (marchandise pas chargée). */
    public const TRANSPORTEUR_MODIFIABLE = ['en_attente', 'prise_en_charge'];

    /** Livraison en cours : un incident de transport peut être déclaré. */
    public const INCIDENT_POSSIBLE = ['prise_en_charge', 'chargee', 'en_cours', 'arrivee'];

    /** Livraison abandonnée (annulée, ou revenue à l'entrepôt) : une nouvelle peut être organisée. */
    public const ABANDONNEES = ['annulee', 'retournee'];

    /** Étapes de transport franchies par le transporteur : étape => statut de départ. */
    public const ETAPES = ['chargee' => 'prise_en_charge', 'en_cours' => 'chargee', 'arrivee' => 'en_cours'];

    private const ACTION_ETAPE = ['chargee' => 'charger', 'en_cours' => 'partir', 'arrivee' => 'arriver'];

    public static function estGestionnaire(Commande $c, Utilisateur $u): bool
    {
        return $c->estGereePar($u);
    }

    /** Actions possibles sur une commande (et sa livraison) pour cet utilisateur. */
    public static function actionsCommande(Commande $c, Utilisateur $u, bool $demandeAnnulationOuverte = false): array
    {
        $actions   = [];
        $l         = $c->livraison;
        $acheteur  = $u->acheteurGros !== null && (int) $u->acheteurGros->id === (int) $c->acheteur_id;
        $nonCharge = ! $l || in_array($l->statut, self::TRANSPORTEUR_MODIFIABLE, true) || in_array($l->statut, self::ABANDONNEES, true);

        if ($acheteur) {
            if ($c->statut === 'en_attente') {
                array_push($actions, 'modifier', 'annuler');
            }
            if (in_array($c->statut, self::ANNULABLES_AVANT_CHARGEMENT, true) && $nonCharge && ! $demandeAnnulationOuverte) {
                $actions[] = 'demander_annulation';
            }
            if ($c->statut === 'en_livraison' && $l?->statut === 'arrivee') {
                array_push($actions, 'confirmer_reception', 'declarer_anomalie');
            }
        }

        if (self::estGestionnaire($c, $u)) {
            match ($c->statut) {
                // Lot 13 : rien à faire tant que l'acheteur n'a pas payé (seul le refus reste possible)
                'en_attente'     => $c->attendPaiement() ? $actions[] = 'refuser' : array_push($actions, 'confirmer', 'refuser'),
                'confirmee'      => $actions[] = 'preparer',
                'en_preparation' => $actions[] = 'marquer_prete',
                'prete'          => ! $l || in_array($l->statut, self::ABANDONNEES, true) ? $actions[] = 'organiser_livraison' : null,
                default          => null,
            };
            if ($l && in_array($l->statut, self::TRANSPORTEUR_MODIFIABLE, true)) {
                $actions[] = 'changer_transporteur';
            }
            if (in_array($c->statut, self::ANNULABLES_AVANT_CHARGEMENT, true) && $nonCharge) {
                $actions[] = 'annuler_commande';
            }
        }

        return $actions;
    }

    /** Actions du transporteur affecté sur une livraison. */
    public static function actionsLivraison(Livraison $l, Utilisateur $u, bool $incidentBloquant = false): array
    {
        $transporteur = $u->transporteur !== null && (int) $u->transporteur->id === (int) $l->transporteur_id;

        if (! $transporteur) {
            return [];
        }

        $actions = [];

        if ($l->statut === 'en_attente' && $l->mission_acceptee_le === null) {
            array_push($actions, 'accepter_mission', 'refuser_mission');
        }

        foreach (self::ETAPES as $etape => $depuis) {
            if ($l->statut === $depuis && ! $incidentBloquant) {
                $actions[] = self::ACTION_ETAPE[$etape];
            }
        }

        if ($l->statut === 'arrivee' && ! $incidentBloquant) {
            $actions[] = 'confirmer_par_code';
        }

        if (in_array($l->statut, self::INCIDENT_POSSIBLE, true)) {
            $actions[] = 'declarer_incident';
        }

        return $actions;
    }

    /** Étape attendue, en clair, et qui doit agir. */
    public static function prochaineEtape(Commande $c): ?array
    {
        $l = $c->livraison;

        return match ($c->statut) {
            'en_attente'     => $c->attendPaiement()
                ? ['libelle' => 'Paiement attendu de l\'acheteur', 'acteur' => 'acheteur_gros']
                : ['libelle' => 'Commande à confirmer par l\'entrepôt', 'acteur' => 'gestionnaire_entrepot'],
            'confirmee'      => ['libelle' => 'Préparation à lancer', 'acteur' => 'gestionnaire_entrepot'],
            'en_preparation' => ['libelle' => 'Commande en préparation', 'acteur' => 'gestionnaire_entrepot'],
            'prete'          => match (true) {
                ! $l || in_array($l->statut, self::ABANDONNEES, true) => ['libelle' => 'Livraison à organiser', 'acteur' => 'gestionnaire_entrepot'],
                ! $l->transporteur_id                          => ['libelle' => 'Transporteur à affecter', 'acteur' => 'gestionnaire_entrepot'],
                $l->statut === 'en_attente'                    => ['libelle' => 'Mission à accepter par le transporteur', 'acteur' => 'transporteur'],
                default                                        => ['libelle' => 'Chargement à l\'entrepôt', 'acteur' => 'transporteur'],
            },
            'en_livraison'   => match ($l?->statut) {
                'chargee'  => ['libelle' => 'Départ vers l\'acheteur', 'acteur' => 'transporteur'],
                'en_cours' => ['libelle' => 'En route vers l\'acheteur', 'acteur' => 'transporteur'],
                'arrivee'  => ['libelle' => 'Réception à confirmer par l\'acheteur', 'acteur' => 'acheteur_gros'],
                default    => null,
            },
            'litige'         => ['libelle' => 'Anomalie à traiter par l\'entrepôt', 'acteur' => 'gestionnaire_entrepot'],
            default          => null,
        };
    }
}
