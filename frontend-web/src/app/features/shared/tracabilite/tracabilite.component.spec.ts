import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { signal } from '@angular/core';
import { of } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { TracabiliteComponent } from './tracabilite.component';

const TRACABILITE = {
  code_tracabilite: 'LOT-2026-TEST',
  etape_production: { type_culture: 'riz', date_recolte: '2026-06-01', quantite_estimee: 100, statut: 'disponible',
    producteur: { nom: 'Ndiaye', prenom: 'Awa', region: 'Thiès' } },
  etapes_stockage: [{
    entrepot: { nom: 'Entrepôt Nord', localisation: 'Thiès' }, produit: 'riz', quantite: 80, date_entree: '2026-06-05', statut: 'disponible',
    commandes: [{ numero_commande: 'CMD-1', produit: 'riz', quantite: 10, date_commande: '2026-06-10 09:00:00', statut: 'confirmee',
      acheteur: null, livraison: { id: 9, statut: 'en_cours', date_depart: '2026-06-11 08:00:00', origine: 'Entrepôt Nord', destination: null } }],
  }],
};

describe('TracabiliteComponent — chronologie', () => {
  function creer(role: string, code: string | null = 'lot-2026-test') {
    const appels: string[] = [];
    TestBed.configureTestingModule({
      imports: [TracabiliteComponent],
      providers: [
        provideRouter([]),
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap(code ? { code } : {}) } } },
        { provide: AuthService, useValue: { role: signal(role) } },
        { provide: ApiService, useValue: { getTracabilite: (c: string) => { appels.push(c); return of({ tracabilite: TRACABILITE }); } } },
      ],
    });
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const fixture = TestBed.createComponent(TracabiliteComponent);
    fixture.detectChanges();
    return { fixture, appels, el: fixture.nativeElement as HTMLElement };
  }

  it('le lien ?code= (depuis la carte) lance la recherche, en majuscules', () => {
    const { appels } = creer('acheteur_gros');
    expect(appels).toEqual(['LOT-2026-TEST']);
  });

  it('affiche les étapes réelles dans l\'ordre chronologique, l\'étape actuelle marquée', () => {
    const { el } = creer('acheteur_gros');
    const titres = Array.from(el.querySelectorAll('.etape__titre')).map(t => t.textContent?.trim());
    expect(titres).toEqual(['Récolte', 'Entrée en entrepôt', 'Commande CMD-1', 'En cours de livraison']);
    expect(el.querySelector('[aria-current="step"] .etape__titre')?.textContent).toContain('En cours de livraison');
    expect(el.textContent).toContain('Dernier lieu enregistré');
    expect(el.textContent).toContain('Entrepôt Nord');
  });

  it('lien vers la carte pour l\'acheteur, absent pour un rôle sans accès au suivi', () => {
    expect(creer('acheteur_gros').el.querySelectorAll('a[href="/suivi-livraison/9"]').length).toBeGreaterThan(0);
    TestBed.resetTestingModule();
    expect(creer('gestionnaire_entrepot').el.querySelectorAll('a[href="/suivi-livraison/9"]').length).toBe(0);
  });

  it('sans code dans l\'adresse, aucune recherche automatique', () => {
    expect(creer('acheteur_gros', null).appels).toEqual([]);
  });
});
