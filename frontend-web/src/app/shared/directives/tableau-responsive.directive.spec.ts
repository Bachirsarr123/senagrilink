import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TableauResponsiveDirective } from './tableau-responsive.directive';

@Component({
  standalone: true,
  imports: [TableauResponsiveDirective],
  template: `
    <table appTableauResponsive>
      <thead><tr><th>Produit</th><th> Quantité </th><th>Actions</th></tr></thead>
      <tbody>
        @for (l of lignes(); track l) {
          <tr><td>{{ l }}</td><td>10 kg</td><td><button>Modifier</button></td></tr>
        }
      </tbody>
    </table>
  `,
})
class HoteComponent {
  lignes = signal(['riz']);
}

describe('TableauResponsiveDirective', () => {
  const cellules = (el: HTMLElement) => Array.from(el.querySelectorAll('tbody td'));

  it('recopie l\'intitulé de chaque colonne dans data-label', () => {
    const fixture = TestBed.createComponent(HoteComponent);
    fixture.detectChanges();

    expect(cellules(fixture.nativeElement).map(td => td.getAttribute('data-label')))
      .toEqual(['Produit', 'Quantité', 'Actions']);
  });

  it('repère la colonne Actions pour la mise en page en cartes', () => {
    const fixture = TestBed.createComponent(HoteComponent);
    fixture.detectChanges();

    const [produit, , actions] = cellules(fixture.nativeElement);
    expect(actions.classList.contains('cellule-actions')).toBe(true);
    expect(produit.classList.contains('cellule-actions')).toBe(false);
  });

  it('étiquette aussi les lignes ajoutées après le premier rendu', async () => {
    const fixture = TestBed.createComponent(HoteComponent);
    fixture.detectChanges();

    fixture.componentInstance.lignes.set(['riz', 'mil']);
    fixture.detectChanges();
    await new Promise(resolve => setTimeout(resolve)); // MutationObserver

    const labels = cellules(fixture.nativeElement).map(td => td.getAttribute('data-label'));
    expect(labels).toEqual(['Produit', 'Quantité', 'Actions', 'Produit', 'Quantité', 'Actions']);
  });
});
