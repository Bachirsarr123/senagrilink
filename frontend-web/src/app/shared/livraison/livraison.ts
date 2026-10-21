/**
 * Circuit « entrepôt → acheteur » et incidents. Comme pour les dépôts, les
 * règles sont décidées par le serveur : chaque commande et chaque livraison
 * arrive avec ses `actions` et sa `prochaine_etape`. Ce fichier ne fait que nommer.
 */

export type ActionCommande =
  | 'modifier' | 'annuler' | 'demander_annulation' | 'confirmer_reception' | 'declarer_anomalie'
  | 'confirmer' | 'refuser' | 'preparer' | 'marquer_prete' | 'organiser_livraison' | 'changer_transporteur' | 'annuler_commande';

export type ActionLivraison =
  | 'accepter_mission' | 'refuser_mission' | 'charger' | 'partir' | 'arriver' | 'confirmer_par_code' | 'declarer_incident';

export const LIBELLE_ACTION_COMMANDE: Record<ActionCommande, string> = {
  modifier:             'Modifier',
  annuler:              'Annuler',
  demander_annulation:  'Demander l\'annulation',
  confirmer_reception:  'J\'ai bien reçu ma commande',
  declarer_anomalie:    'Signaler un problème',
  confirmer:            'Confirmer',
  refuser:              'Refuser',
  preparer:             'Lancer la préparation',
  marquer_prete:        'Commande prête',
  organiser_livraison:  'Organiser la livraison',
  changer_transporteur: 'Changer de transporteur',
  annuler_commande:     'Annuler la commande',
};

export const LIBELLE_ACTION_LIVRAISON: Record<ActionLivraison, string> = {
  accepter_mission:   'Accepter la mission',
  refuser_mission:    'Refuser la mission',
  charger:            'Marchandise chargée',
  partir:             'Je pars vers l\'acheteur',
  arriver:            'Je suis arrivé chez l\'acheteur',
  confirmer_par_code: 'Remettre avec le code',
  declarer_incident:  'Déclarer un incident',
};

/** Action de transport → étape envoyée à PUT /livraisons/{id}/etape. */
export const ETAPE_LIVRAISON: Partial<Record<ActionLivraison, string>> = { charger: 'chargee', partir: 'en_cours', arriver: 'arrivee' };

export const CONFIRMATION_ETAPE_LIVRAISON: Partial<Record<ActionLivraison, string>> = {
  charger: 'Confirmer le chargement ? La marchandise sort du stock et l\'acheteur reçoit son code de réception.',
  partir:  'Confirmer le départ vers l\'acheteur ? Il sera prévenu.',
  arriver: 'Confirmer l\'arrivée chez l\'acheteur ? Demandez-lui ensuite son code de réception.',
};

export interface Choix { code: string; libelle: string; }

export const TYPES_INCIDENT: Record<'livraison' | 'reservation', Choix[]> = {
  livraison: [
    { code: 'panne', libelle: 'Panne du véhicule' },
    { code: 'retard', libelle: 'Retard' },
    { code: 'acces_impossible', libelle: 'Accès impossible' },
    { code: 'acheteur_absent', libelle: 'Acheteur absent' },
    { code: 'marchandise_abimee', libelle: 'Marchandise abîmée' },
    { code: 'autre', libelle: 'Autre problème' },
  ],
  reservation: [
    { code: 'panne', libelle: 'Panne du véhicule' },
    { code: 'retard', libelle: 'Retard' },
    { code: 'acces_impossible', libelle: 'Accès impossible' },
    { code: 'marchandise_abimee', libelle: 'Marchandise abîmée' },
    { code: 'autre', libelle: 'Autre problème' },
  ],
};

export const ANOMALIES: Choix[] = [
  { code: 'quantite_manquante', libelle: 'Quantité manquante' },
  { code: 'produit_abime', libelle: 'Produit abîmé' },
  { code: 'livraison_incorrecte', libelle: 'Livraison incorrecte (mauvais produit)' },
];

export const LIBELLE_DECISION: Record<string, string> = {
  reprendre:             'La livraison reprend',
  changer_transporteur:  'Confier à un autre transporteur',
  retour_entrepot:       'Retour à l\'entrepôt (nouvelle livraison ensuite)',
  annuler_commande:      'Annuler la commande (marchandise remise en stock)',
  annuler_depot:         'Annuler le dépôt',
  accepter_annulation:   'Accepter l\'annulation',
  refuser_annulation:    'Refuser l\'annulation',
  cloturer_litige:       'Clore le litige (arrangement trouvé)',
  reprendre_marchandise: 'Reprendre la marchandise et annuler',
};

/** Décisions qui exigent un motif (le serveur le vérifie aussi). */
export const DECISION_AVEC_MOTIF = ['refuser_annulation', 'cloturer_litige', 'annuler_commande', 'annuler_depot', 'reprendre_marchandise'];

export function libelleIncident(code: string): string {
  return [...TYPES_INCIDENT.livraison, ...ANOMALIES, { code: 'demande_annulation', libelle: 'Demande d\'annulation' }]
    .find(t => t.code === code)?.libelle ?? code;
}

export function peutFaire(o: { actions?: string[] } | null | undefined, action: string): boolean {
  return !!o?.actions?.includes(action);
}
