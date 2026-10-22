import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { MesCommandesComponent } from './acheteur/mes-commandes/mes-commandes.component';
import { PaiementComponent } from './acheteur/paiement/paiement.component';
import { ArgentComponent } from './shared/argent/argent.component';

/** Lot 12 — paiements simulés comme des vrais, « Mon argent ». */
const API = environment.apiUrl;
const COMMANDE = { id: 8, numero_commande: 'CMD-8', produit: 'riz', quantite: 10, prix: 1500, prix_unitaire: 150, frais_livraison: 1200,
  total_a_payer: 2700, statut: 'confirmee', statut_paiement: 'a_payer', actions: [], incidents: [] };

describe('Lot 12 — site web', () => {
  let http: HttpTestingController;
  function configurer(role: string, ref = ''): void {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Sow', prenom: 'Fatou', role }));
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => ref } } } }] });
    http = TestBed.inject(HttpTestingController);
  }
  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  it('Mes commandes : total avec la livraison, « Payer avec Wave » ouvre la page de paiement', () => {
    configurer('acheteur_gros');
    const nav = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const fixture = TestBed.createComponent(MesCommandesComponent);
    fixture.detectChanges();
    http.expectOne(r => r.url.startsWith(`${API}/commandes/historique`)).flush({ commandes: [COMMANDE] });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.textContent).toContain('2,700 FCFA');
    expect(el.textContent).toContain('livraison 1,200');
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Payer avec Wave')) as HTMLButtonElement).click();
    const req = http.expectOne(`${API}/paiements`);
    expect(req.request.body).toEqual({ commandes: [8], moyen: 'wave' });
    expect(req.request.headers.get('Idempotency-Key')).toBeTruthy();
    req.flush({ paiement: { reference: 'PAY-1' } });
    expect(nav).toHaveBeenCalledWith(['/paiement', 'PAY-1']);
  });

  it('Page de paiement simulée : confirmer, l\'argent est mis de côté', () => {
    configurer('acheteur_gros', 'PAY-1');
    const fixture = TestBed.createComponent(PaiementComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/paiements/PAY-1`).flush({ paiement: { reference: 'PAY-1', montant: 2700, moyen: 'wave', fournisseur: 'simulation',
      statut: 'initie', telephone: '771234567', commandes: [COMMANDE] } });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.textContent).toContain('Simulation : aucun argent réel');
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Confirmer le paiement')) as HTMLButtonElement).click();
    http.expectOne(`${API}/paiements/PAY-1/simulation`).flush({ message: 'Paiement de 2 700 FCFA réussi.', paiement: { reference: 'PAY-1', montant: 2700,
      moyen: 'wave', fournisseur: 'simulation', statut: 'reussi', commandes: [COMMANDE] } });
    fixture.detectChanges();
    expect(el.textContent).toContain('Paiement de 2 700 FCFA réussi.');
    expect(el.textContent).toContain('mis de côté');
  });

  it('Mon argent : mis de côté, versé, détail d\'une vente ; numéro Wave enregistré', () => {
    configurer('producteur');
    const fixture = TestBed.createComponent(ArgentComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/argent`).flush({ totaux: { mis_de_cote: 1420, verse: 2840, a_regler: 0, regle: 0 }, moyen_paiement: 'wave', numero_paiement: '771112233',
      versements: [{ id: 1, beneficiaire: 'producteur', montant: 1420, statut: 'verse', telephone: '771112233', verse_le: '2026-10-22T10:00:00Z',
        commande: { numero_commande: 'CMD-8', produit: 'riz', quantite: 10, prix: 1500, frais_stockage: 50, commission: 30 } }] });
    fixture.detectChanges();
    const texte = fixture.nativeElement.textContent;
    expect(texte).toContain('1,420 FCFA');
    expect(texte).toContain('1,500 FCFA − entrepôt 50 − commission 30');
    (Array.from(fixture.nativeElement.querySelectorAll('button')).find((b: any) => b.textContent?.includes('Enregistrer')) as HTMLButtonElement).click();
    const req = http.expectOne(`${API}/profile`);
    expect(req.request.body).toEqual({ moyen_paiement: 'wave', numero_paiement: '771112233' });
    req.flush({});
    http.expectOne(`${API}/argent`).flush({ totaux: { mis_de_cote: 0, verse: 0, a_regler: 0, regle: 0 }, versements: [] });
  });
});
