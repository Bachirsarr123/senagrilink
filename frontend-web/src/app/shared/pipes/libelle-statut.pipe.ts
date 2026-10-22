import { Pipe, PipeTransform } from '@angular/core';

/** Libellés affichés pour les codes de statut et de rôle renvoyés par l'API. */
const LIBELLES: Record<string, string> = {
  en_attente:            'En attente',
  confirmee:             'Confirmée',
  annulee:               'Annulée',
  livree:                'Livrée',
  en_cours:              'En cours',
  probleme:              'Problème',
  disponible:            'Disponible',
  epuise:                'Épuisé',
  actif:                 'Actif',
  bloque:                'Bloqué',
  arrivee_entrepot:      'Arrivée à l\'entrepôt',
  enregistree:           'Enregistrée',
  refusee:               'Refusée',
  prise_en_charge:       'Mission acceptée',
  au_point_collecte:     'Chez le producteur',
  chargee:               'Chargée',
  en_route:              'En route',
  reception_refusee:     'Réception refusée',
  en_preparation:        'En préparation',
  prete:                 'Prête',
  en_livraison:          'En livraison',
  receptionnee:          'Reçue',
  litige:                'Litige',
  arrivee:               'Arrivée chez l\'acheteur',
  retournee:             'Revenue à l\'entrepôt',
  ouvert:                'Ouvert',
  en_attente_validation: 'En attente de validation',
  refuse:                'Refusé',
  resolu:                'Traité',
  producteur:            'Producteur',
  gestionnaire_entrepot: 'Gestionnaire d\'entrepôt',
  acheteur_gros:         'Acheteur en gros',
  transporteur:          'Transporteur',
  administrateur:        'Administrateur',
};

/**
 * « en_attente » → « En attente ». Affichage uniquement : les classes CSS
 * (badge-en_attente…) et les valeurs envoyées à l'API restent les codes bruts.
 * Un code inconnu est rendu lisible (tirets bas remplacés, majuscule initiale).
 */
@Pipe({ name: 'libelleStatut', standalone: true })
export class LibelleStatutPipe implements PipeTransform {
  transform(code: unknown): string {
    if (code === null || code === undefined || code === '') return '';
    const valeur  = String(code);
    const libelle = LIBELLES[valeur] ?? valeur.replace(/_/g, ' ');
    return libelle.charAt(0).toUpperCase() + libelle.slice(1);
  }
}
