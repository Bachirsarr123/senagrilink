/** Lot 12 : libellés des paiements, mêmes mots sur le site et l'application. */
export const ETATS_PAIEMENT: Record<string, string> = {
  a_payer: 'À payer',
  paye: 'Payé — mis de côté jusqu\'à la réception',
  a_la_livraison: 'À payer en espèces à la livraison',
  libere: 'Payé et versé',
  encaisse: 'Payé en espèces',
  litige: 'Bloqué : litige en cours',
  rembourse: 'Remboursé',
  annule: 'Annulé',
};

export const MOYENS: Record<string, string> = { wave: 'Wave', orange_money: 'Orange Money', especes: 'Espèces' };

export const ETATS_VERSEMENT: Record<string, string> = {
  bloque: 'Mis de côté', verse: 'Versé', a_regler: 'À recevoir en main propre', regle: 'Reçu en main propre', annule: 'Annulé',
};

export const BENEFICIAIRES: Record<string, string> = {
  producteur: 'Vente de votre marchandise', entrepot: 'Frais d\'entrepôt', transporteur: 'Livraison', plateforme: 'Commission SenAgriLink',
};
