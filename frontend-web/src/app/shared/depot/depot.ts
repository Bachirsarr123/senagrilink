/**
 * Circuit « récolte → entrepôt ». Les règles (qui peut faire quoi, à quelle
 * étape) sont décidées par le serveur : chaque réservation arrive avec ses
 * `actions` possibles et sa `prochaine_etape`. Ce fichier ne fait que nommer.
 */

export type ActionDepot =
  | 'confirmer' | 'refuser' | 'remettre_en_attente' | 'assigner' | 'retirer_transporteur'
  | 'receptionner' | 'refuser_reception' | 'accepter_mission' | 'refuser_mission'
  | 'arriver_collecte' | 'charger' | 'partir' | 'arriver' | 'annuler';

/** Action de transport → étape envoyée à PUT /reservations/{id}/etape. */
export const ETAPE_DE_L_ACTION: Partial<Record<ActionDepot, string>> = {
  arriver_collecte: 'au_point_collecte',
  charger:          'chargee',
  partir:           'en_route',
  arriver:          'arrivee_entrepot',
};

/** Libellés des boutons, identiques sur le site et l'application. */
export const LIBELLE_ACTION: Record<ActionDepot, string> = {
  confirmer:            'Accepter le dépôt',
  refuser:              'Refuser',
  remettre_en_attente:  'Annuler l\'acceptation',
  assigner:             'Affecter un transporteur',
  retirer_transporteur: 'Retirer la mission',
  receptionner:         'Contrôler la réception',
  refuser_reception:    'Refuser la marchandise',
  accepter_mission:     'Accepter la mission',
  refuser_mission:      'Refuser la mission',
  arriver_collecte:     'Je suis chez le producteur',
  charger:              'Marchandise chargée',
  partir:               'Je pars vers l\'entrepôt',
  arriver:              'Je suis arrivé à l\'entrepôt',
  annuler:              'Annuler le dépôt',
};

/** Confirmation affichée avant une étape de transport (action sur le terrain, difficile à défaire). */
export const CONFIRMATION_ETAPE: Partial<Record<ActionDepot, string>> = {
  arriver_collecte: 'Confirmer votre arrivée chez le producteur ?',
  charger:          'Confirmer que la marchandise est chargée ?',
  partir:           'Confirmer le départ vers l\'entrepôt ? L\'entrepôt sera prévenu.',
  arriver:          'Confirmer l\'arrivée à l\'entrepôt ? Le gestionnaire sera prévenu pour la réception.',
};

export const LIBELLE_MODE: Record<string, string> = {
  transporteur: 'Par un transporteur',
  producteur:   'Livré par le producteur',
};

export const ETATS_MARCHANDISE: { code: string; libelle: string }[] = [
  { code: 'bon',        libelle: 'Bon état' },
  { code: 'acceptable', libelle: 'Acceptable (quelques défauts)' },
  { code: 'abime',      libelle: 'Abîmé' },
];

/** Étapes normales du circuit, distinctes des corrections dans l'historique. */
export const ETAPES_NORMALES = ['creation', 'confirmation', 'acceptation_mission', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot', 'reception', 'arrivee'];

export function peut(r: { actions?: string[] } | null | undefined, action: ActionDepot): boolean {
  return !!r?.actions?.includes(action);
}
