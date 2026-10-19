import { AfterViewInit, Directive, ElementRef, OnDestroy, inject } from '@angular/core';

/**
 * Tableaux lisibles sur téléphone : recopie l'intitulé de chaque colonne
 * (<th>) dans l'attribut data-label des cellules. Jusqu'à 900 px, le CSS
 * (shared.styles.scss) affiche alors chaque ligne comme une carte
 * « intitulé : valeur » au lieu de colonnes coupées hors de l'écran.
 * Les lignes ajoutées ou modifiées par Angular sont étiquetées au fil de l'eau.
 */
@Directive({
  selector: 'table[appTableauResponsive]',
  standalone: true,
})
export class TableauResponsiveDirective implements AfterViewInit, OnDestroy {
  private readonly table = inject<ElementRef<HTMLTableElement>>(ElementRef).nativeElement;
  private observateur?: MutationObserver;

  ngAfterViewInit(): void {
    this.etiqueter();
    if (typeof MutationObserver !== 'undefined') {
      this.observateur = new MutationObserver(() => this.etiqueter());
      this.observateur.observe(this.table, { childList: true, subtree: true });
    }
  }

  ngOnDestroy(): void {
    this.observateur?.disconnect();
  }

  private etiqueter(): void {
    const intitules = Array.from(this.table.querySelectorAll('thead th'))
      .map(th => (th.textContent ?? '').trim());

    this.table.querySelectorAll('tbody tr').forEach(ligne => {
      Array.from(ligne.children).forEach((cellule, index) => {
        const intitule = intitules[index] ?? '';
        if (cellule.getAttribute('data-label') !== intitule) {
          cellule.setAttribute('data-label', intitule);
        }
        cellule.classList.toggle('cellule-actions', intitule.toLowerCase() === 'actions');
      });
    });
  }
}
