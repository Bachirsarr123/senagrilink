import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { CatalogueComponent } from './acheteur/catalogue/catalogue.component';

/** Lot 13 — commander et payer d'un seul appui depuis le catalogue. */
const API = environment.apiUrl;
const PRODUIT = { stock_id: 5, produit: 'riz', quantite_disponible: 40, prix_unitaire: 150 };

describe('Lot 13 — commander et payer d\'un seul appui', () => {
  let http: HttpTestingController;
  function creer(): { comp: CatalogueComponent; el: HTMLElement; nav: ReturnType<typeof vi.spyOn>; fixture: any } {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Sow', prenom: 'Fatou', role: 'acheteur_gros' }));
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
    const nav = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const fixture = TestBed.createComponent(CatalogueComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/catalogue`).flush({ catalogue: [PRODUIT] });
    fixture.detectChanges();
    const comp = fixture.componentInstance;
    comp.ouvrirCommande(PRODUIT);
    comp.formCommande.setValue({ quantite: 10 });
    fixture.detectChanges();
    http.match(r => r.url.includes('/commandes/devis')).forEach(r => r.flush({ devis: null }));
    return { comp, el: fixture.nativeElement, nav, fixture };
  }
  const bouton = (el: HTMLElement, texte: string) =>
    Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes(texte)) as HTMLButtonElement;
  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  it('« Payer avec Wave » : la commande est passée avec le moyen, la page de paiement s\'ouvre aussitôt', () => {
    const { el, nav } = creer();
    expect(el.textContent).not.toContain('Confirmer la commande');
    bouton(el, 'Payer avec Wave').click();
    const req = http.expectOne(`${API}/commandes`);
    expect(req.request.body).toMatchObject({ stock_id: 5, quantite: 10, moyen: 'wave' });
    req.flush({ message: 'Confirmez le paiement', commande: { id: 8 }, paiement: { reference: 'PAY-1', moyen: 'wave' } }, { status: 201, statusText: 'Created' });
    expect(nav).toHaveBeenCalledWith(['/paiement', 'PAY-1']);
  });

  it('Orange Money : même parcours', () => {
    const { el, nav } = creer();
    bouton(el, 'Orange Money').click();
    const req = http.expectOne(`${API}/commandes`);
    expect(req.request.body.moyen).toBe('orange_money');
    req.flush({ commande: { id: 8 }, paiement: { reference: 'PAY-2', moyen: 'orange_money' } }, { status: 201, statusText: 'Created' });
    expect(nav).toHaveBeenCalledWith(['/paiement', 'PAY-2']);
  });

  it('Espèces : commande validée tout de suite, montant à remettre au transporteur affiché', () => {
    const { el, nav, fixture } = creer();
    bouton(el, 'En espèces à la livraison').click();
    const req = http.expectOne(`${API}/commandes`);
    expect(req.request.body.moyen).toBe('especes');
    req.flush({ message: 'Commande passée. Vous paierez 2 700 FCFA en espèces au transporteur, à la livraison.', commande: { id: 8 },
      paiement: { reference: 'PAY-3', moyen: 'especes' } }, { status: 201, statusText: 'Created' });
    http.expectOne(`${API}/catalogue`).flush({ catalogue: [PRODUIT] });
    fixture.detectChanges();
    expect(nav).not.toHaveBeenCalled();
    expect(el.textContent).toContain('Vous paierez 2 700 FCFA en espèces au transporteur');
  });

  it('Panier : payé d\'un seul appui, sans fenêtre de confirmation', () => {
    const { comp, el, nav, fixture } = creer();
    const confirmation = vi.spyOn(window, 'confirm');
    comp.ajouterAuPanier(PRODUIT);
    fixture.detectChanges();
    bouton(el, 'Payer le panier avec Wave').click();
    const req = http.expectOne(`${API}/commandes`);
    expect(req.request.body).toMatchObject({ lignes: [{ stock_id: 5, quantite: 10 }], moyen: 'wave' });
    req.flush({ commandes: [{ id: 8 }], paiement: { reference: 'PAY-4', moyen: 'wave' } }, { status: 201, statusText: 'Created' });
    expect(confirmation).not.toHaveBeenCalled();
    expect(nav).toHaveBeenCalledWith(['/paiement', 'PAY-4']);
  });
});
