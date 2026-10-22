import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { JourneeComponent } from './entrepot/journee/journee.component';
import { AgriculteursComponent } from './entrepot/agriculteurs/agriculteurs.component';

/** Lot 11 — pilote automatique, « Ma journée » et agriculteurs du gestionnaire. */
const API = environment.apiUrl;

const JOURNEE = {
  attention: [],
  arrivent: [{ id: 3, numero: 'RES-3', produit: 'mil', quantite: 40, statut: 'en_route', etape: 'En route vers l\'entrepôt',
    producteur: { nom: 'Awa Diallo' }, transporteur: { nom: 'Alioune Fall' }, arrivee_estimee_le: '2026-10-22T14:35:00Z', a_receptionner: false }],
  a_preparer: [{ id: 8, numero: 'CMD-8', produit: 'riz', quantite: 10, statut: 'confirmee', acheteur: { nom: 'Fatou Sow' }, action: 'prete' }],
  partent: [{ id: 5, numero: 'LIV-5', commande_id: 9, produit: 'maïs', quantite: 5, statut: 'en_attente', acheteur: { nom: 'Modou Kane' },
    transporteur: null, sans_transporteur: true, mission_acceptee: false }],
  pilote: { auto_accepter_depots: true, auto_confirmer_commandes: true, auto_affecter_transporteurs: true, delai_reponse_min: 10 },
};

describe('Lot 11 — site web', () => {
  let http: HttpTestingController;

  function configurer(): void {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Ndiaye', prenom: 'Ibra', role: 'gestionnaire_entrepot' }));
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
  }
  afterEach(() => { http?.verify(); localStorage.clear(); });

  it('Ma journée : à traiter, arrivent, à préparer, partent ; « Commande prête » d\'un appui ; réglages du pilote', () => {
    configurer();
    const fixture = TestBed.createComponent(JourneeComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/journee`).flush(JOURNEE);
    http.expectOne(`${API}/a-faire`).flush({ elements: [] });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.textContent).toContain('Rien ne demande votre attention');
    expect(el.textContent).toContain('Awa Diallo');
    expect(el.textContent).toContain('🚚 Alioune Fall');
    expect(el.textContent).toContain('Fatou Sow');
    expect(el.querySelector('a[href="/commandes/9"]')?.textContent).toContain('Choisir un transporteur');

    // « Commande prête » : préparation puis prête ; le transporteur est trouvé par le serveur
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Commande prête')) as HTMLButtonElement).click();
    http.expectOne(`${API}/commandes/8/preparer`).flush({});
    http.expectOne(`${API}/commandes/8/prete`).flush({ message: 'Commande prête. Un transporteur a été prévenu automatiquement.' });
    http.expectOne(`${API}/journee`).flush(JOURNEE);
    fixture.detectChanges();
    expect(el.textContent).toContain('Un transporteur a été prévenu automatiquement.');

    // Couper l'acceptation automatique des dépôts
    const caseDepots = Array.from(el.querySelectorAll('label')).find(l => l.textContent?.includes('Accepter les dépôts'))!.querySelector('input') as HTMLInputElement;
    caseDepots.checked = false;
    caseDepots.dispatchEvent(new Event('change'));
    const req = http.expectOne(`${API}/profile`);
    expect(req.request.body).toEqual({ auto_accepter_depots: false });
    req.flush({ utilisateur: { id: 1, role: 'gestionnaire_entrepot' } });
    fixture.destroy();
  });

  it('Agriculteurs : recherche, puis fiche (stock, dépôts, ventes)', async () => {
    configurer();
    const fixture = TestBed.createComponent(AgriculteursComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/entrepot/producteurs`).flush({ producteurs: [] });
    const champ = fixture.nativeElement.querySelector('input[type=search]') as HTMLInputElement;
    champ.value = 'awa';
    champ.dispatchEvent(new Event('input'));
    await new Promise(r => setTimeout(r, 300));
    http.expectOne(r => r.url === `${API}/entrepot/producteurs` && r.params.get('q') === 'awa')
      .flush({ producteurs: [{ id: 2, nom: 'Awa Diallo', telephone: '771112233', region: 'Thiès', stock_kg: 80, depots_en_cours: 1 }] });
    fixture.detectChanges();
    (fixture.nativeElement.querySelector('.liste button') as HTMLButtonElement).click();
    http.expectOne(`${API}/entrepot/producteurs/2`).flush({
      producteur: { id: 2, nom: 'Awa Diallo', telephone: '771112233', region: 'Thiès' },
      stocks: [{ id: 1, produit: 'riz', quantite_disponible: 70, lot: 'LOT-1' }],
      depots: [{ id: 3, numero_reservation: 'RES-3', produit: 'riz', quantite_reservee: 80, statut: 'enregistree' }],
      ventes: [{ id: 8, numero_commande: 'CMD-8', quantite: 10, prix: 1500, statut: 'livree' }],
    });
    fixture.detectChanges();
    const texte = fixture.nativeElement.textContent;
    expect(texte).toContain('70 kg disponibles (lot LOT-1)');
    expect(texte).toContain('RES-3');
    expect(texte).toContain('CMD-8');
  });
});
