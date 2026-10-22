/// Circuit « entrepôt → acheteur » et incidents. Comme pour les dépôts, les
/// règles sont décidées par le serveur : chaque commande et chaque livraison
/// arrive avec ses `actions` et sa `prochaine_etape`. Même vocabulaire que le
/// site (frontend-web/src/app/shared/livraison/livraison.ts).
library;

const libelleActionCommande = {
  'modifier': 'Modifier',
  'annuler': 'Annuler',
  'demander_annulation': 'Demander l\'annulation',
  'confirmer_reception': 'J\'ai bien reçu ma commande',
  'declarer_anomalie': 'Signaler un problème',
};

const libelleActionLivraison = {
  'accepter_mission': 'Accepter la mission',
  'refuser_mission': 'Refuser la mission',
  'charger': 'Marchandise chargée',
  'partir': 'Je pars vers l\'acheteur',
  'arriver': 'Je suis arrivé chez l\'acheteur',
  'confirmer_par_code': 'Remettre avec le code',
  'declarer_incident': 'Déclarer un incident',
};

/// Action de transport → étape envoyée à PUT /livraisons/{id}/etape.
const etapeLivraison = {'charger': 'chargee', 'partir': 'en_cours', 'arriver': 'arrivee'};

const confirmationEtapeLivraison = {
  'charger': 'Confirmer le chargement ? La marchandise sort du stock et l\'acheteur reçoit son code de réception.',
  'partir': 'Confirmer le départ vers l\'acheteur ? Il sera prévenu et votre position sera partagée.',
  'arriver': 'Confirmer l\'arrivée chez l\'acheteur ? Demandez-lui ensuite son code de réception.',
};

/// Livraison en cours pour le transporteur.
const statutsLivraisonActifs = ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee'];

typedef Choix = ({String code, String libelle});

const List<Choix> typesIncidentLivraison = [
  (code: 'panne', libelle: 'Panne du véhicule'),
  (code: 'retard', libelle: 'Retard'),
  (code: 'acces_impossible', libelle: 'Accès impossible'),
  (code: 'acheteur_absent', libelle: 'Acheteur absent'),
  (code: 'marchandise_abimee', libelle: 'Marchandise abîmée'),
  (code: 'autre', libelle: 'Autre problème'),
];

const List<Choix> typesIncidentDepot = [
  (code: 'panne', libelle: 'Panne du véhicule'),
  (code: 'retard', libelle: 'Retard'),
  (code: 'acces_impossible', libelle: 'Accès impossible'),
  (code: 'marchandise_abimee', libelle: 'Marchandise abîmée'),
  (code: 'autre', libelle: 'Autre problème'),
];

const List<Choix> anomalies = [
  (code: 'quantite_manquante', libelle: 'Quantité manquante'),
  (code: 'produit_abime', libelle: 'Produit abîmé'),
  (code: 'livraison_incorrecte', libelle: 'Livraison incorrecte (mauvais produit)'),
];

const libelleDecision = {
  'reprendre': 'la livraison reprend',
  'changer_transporteur': 'un autre transporteur prend le relais',
  'retour_entrepot': 'retour à l\'entrepôt',
  'annuler_commande': 'commande annulée',
  'annuler_depot': 'dépôt annulé',
  'accepter_annulation': 'annulation acceptée',
  'refuser_annulation': 'annulation refusée',
  'cloturer_litige': 'litige clos',
  'reprendre_marchandise': 'marchandise reprise, commande annulée',
};

String libelleIncident(String code) =>
    [...typesIncidentLivraison, ...anomalies, (code: 'demande_annulation', libelle: 'Demande d\'annulation')]
        .where((t) => t.code == code)
        .map((t) => t.libelle)
        .firstOrNull ??
    code;
