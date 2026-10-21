/// Circuit « récolte → entrepôt ». Les règles (qui peut faire quoi, à quelle
/// étape) sont décidées par le serveur : chaque réservation arrive avec ses
/// `actions` possibles et sa `prochaine_etape`. Même vocabulaire que le site
/// (frontend-web/src/app/shared/depot/depot.ts).
library;

/// Action de transport → étape envoyée à PUT /reservations/{id}/etape.
const etapeDeLAction = {
  'arriver_collecte': 'au_point_collecte',
  'charger': 'chargee',
  'partir': 'en_route',
  'arriver': 'arrivee_entrepot',
};

const libelleAction = {
  'confirmer': 'Accepter le dépôt',
  'refuser': 'Refuser',
  'remettre_en_attente': 'Annuler l\'acceptation',
  'assigner': 'Affecter un transporteur',
  'retirer_transporteur': 'Retirer la mission',
  'receptionner': 'Contrôler la réception',
  'refuser_reception': 'Refuser la marchandise',
  'accepter_mission': 'Accepter la mission',
  'refuser_mission': 'Refuser la mission',
  'arriver_collecte': 'Je suis chez le producteur',
  'charger': 'Marchandise chargée',
  'partir': 'Je pars vers l\'entrepôt',
  'arriver': 'Je suis arrivé à l\'entrepôt',
  'annuler': 'Annuler le dépôt',
  'declarer_incident': 'Déclarer un incident',
};

/// Confirmation demandée avant une étape de transport (action sur le terrain).
const confirmationEtape = {
  'arriver_collecte': 'Confirmer votre arrivée chez le producteur ?',
  'charger': 'Confirmer que la marchandise est chargée ?',
  'partir': 'Confirmer le départ vers l\'entrepôt ? L\'entrepôt sera prévenu.',
  'arriver': 'Confirmer l\'arrivée à l\'entrepôt ? Le gestionnaire sera prévenu pour la réception.',
};

const libelleMode = {
  'transporteur': 'Par un transporteur',
  'producteur': 'Livré par le producteur',
};

/// Statuts d'un dépôt encore en cours (ni terminé, ni annulé, ni refusé).
const statutsDepotActifs = [
  'en_attente', 'confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route', 'arrivee_entrepot',
];

List<String> actionsDe(Map<String, dynamic> r) => ((r['actions'] as List?) ?? []).cast<String>();

bool peut(Map<String, dynamic> r, String action) => actionsDe(r).contains(action);
