import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { PlanifierVentesComponent } from './planifier-ventes.component';

/** Demandes d'acheteurs : contrat de GET /api/commandes/disponibles = { commandes_disponibles: [...] }. */
describe('PlanifierVentesComponent — demandes d\'acheteurs', () => {
  const demande = {
    id: 7, numero_commande: 'CMD-TEST', produit: 'riz', quantite: 10, prix: null,
    date_commande: '2026-06-02T10:00:00Z', statut: 'en_attente', acheteur: null, acheteur_masque: true,
  };

  function creer(commandesDisponibles: () => unknown) {
    TestBed.configureTestingModule({
      imports: [PlanifierVentesComponent],
      providers: [
        provideRouter([]),
        {
          provide: ApiService,
          useValue: {
            getProductions: () => of({ productions: [] }),
            getCommandesDisponibles: commandesDisponibles,
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(PlanifierVentesComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('affiche les demandes renvoyées par l\'API', () => {
    const fixture = creer(() => of({ commandes_disponibles: [demande] }));

    expect(fixture.componentInstance.demandes()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('CMD-TEST');
  });

  it('affiche une erreur au lieu d\'une liste vide si l\'appel échoue', () => {
    const fixture = creer(() => throwError(() => ({ error: { message: 'Erreur serveur' } })));
    fixture.detectChanges();

    expect(fixture.componentInstance.erreurDemandes()).toBe('Erreur serveur');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Erreur serveur');
  });
});
