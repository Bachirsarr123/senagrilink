import { AbstractControl } from '@angular/forms';

/**
 * Confirmations des actions sensibles. Fenêtres natives du navigateur :
 * accessibles, bloquantes et disponibles aussi dans la PWA installée.
 */

/** Fermer un formulaire : confirmation uniquement si des informations saisies seraient perdues. */
export function abandonConfirme(form: AbstractControl): boolean {
  return !form.dirty || confirm('Les informations saisies seront perdues. Fermer sans enregistrer ?');
}

/**
 * Demande le motif d'une action métier après en avoir expliqué les conséquences.
 * Renvoie null si l'utilisateur renonce ; '' si le motif est facultatif et laissé vide.
 */
export function demanderMotif(consequences: string, obligatoire: boolean): string | null {
  const libelle = obligatoire ? 'Motif (obligatoire) :' : 'Motif (facultatif) :';
  while (true) {
    const saisie = prompt(`${consequences}\n\n${libelle}`, '');
    if (saisie === null) return null;
    const motif = saisie.trim();
    if (motif || !obligatoire) return motif;
  }
}
