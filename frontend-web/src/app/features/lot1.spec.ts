import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { CatalogueComponent } from './acheteur/catalogue/catalogue.component';
import { StocksComponent, libelleMouvement } from './entrepot/stocks/stocks.component';
import { ProductionsComponent } from './producteur/productions/productions.component';
import { ProducteurReservationsComponent as ReservationsComponent } from './producteur/reservations/reservations.component';

/** Lot 1 — stock réservé/disponible, ajustements motivés, prix du producteur, lot verrouillé. */
const API = environment.apiUrl;

describe('Lot 1 — site web', () => {
  let http: HttpTestingController;

  function configurer(): void {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
  }

  afterEach(() => { http?.verify(); vi.restoreAllMocks(); });

  const STOCK = { id: 7, produit: 'riz', quantite: 60, quantite_reservee: 10, quantite_disponible: 50, seuil_alerte: 55, statut: 'disponible' };

  function stocks() {
    configurer();
    const fixture = TestBed.createComponent(StocksComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/stocks`).flush({ stocks: [STOCK] });
    fixture.detectChanges();
    return { fixture, comp: fixture.componentInstance };
  }

  it('stocks : affiche physique, réservé et disponible ; alerte sur le disponible', () => {
    const { fixture, comp } = stocks();
    const texte = fixture.nativeElement.textContent as string;
    expect(texte).toContain('60 kg');
    expect(texte).toContain('10 kg');
    expect(texte).toContain('50 kg');
    expect(texte).not.toContain('+ Entrée'); // plus d'entrée libre
    expect(comp.enAlerte(STOCK)).toBe(true);  // 50 <= 55 alors que le physique (60) dépasse
  });

  it('stocks : ajustement motivé envoyé avec une clé, puis liste rechargée', () => {
    const { comp } = stocks();
    comp.ouvrirAjustement(STOCK);
    comp.formAjustement.setValue({ motif_type: 'avarie', sens: 'retrait', quantite: 5, motif: 'Sacs mouillés' });
    comp.enregistrerAjustement();

    const req = http.expectOne(`${API}/stocks/7/ajustement`);
    expect(req.request.body).toEqual({ motif_type: 'avarie', sens: 'retrait', quantite: 5, motif: 'Sacs mouillés' });
    expect(req.request.headers.get('Idempotency-Key')).toMatch(/^[A-Za-z0-9_-]{8,100}$/);
    req.flush({ stock: STOCK });
    http.expectOne(`${API}/stocks`).flush({ stocks: [STOCK] });
    expect(comp.succes()).toContain('riz');
  });

  it('stocks : sans explication, rien n\'est envoyé ; « ajouter » revient à « retirer » hors correction d\'inventaire', () => {
    const { comp } = stocks();
    comp.ouvrirAjustement(STOCK);
    comp.formAjustement.setValue({ motif_type: 'correction_inventaire', sens: 'ajout', quantite: 2, motif: '' });
    comp.enregistrerAjustement();
    http.expectNone(`${API}/stocks/7/ajustement`);

    comp.formAjustement.patchValue({ motif_type: 'perte' });
    comp.raisonChangee();
    expect(comp.formAjustement.value.sens).toBe('retrait');
  });

  it('stocks : journal des mouvements et seuil', () => {
    const { comp } = stocks();
    comp.ouvrirJournal(STOCK);
    http.expectOne(`${API}/stocks/7/mouvements`).flush({ mouvements: [{ id: 1, type: 'entree', variation_physique: 60, variation_reservee: 0, physique_apres: 60, reservee_apres: 0 }] });
    expect(comp.mouvements().length).toBe(1);

    vi.spyOn(window, 'prompt').mockReturnValue('');
    comp.modifierSeuil(STOCK);
    const seuil = http.expectOne(`${API}/stocks/7/seuil`);
    expect(seuil.request.body).toEqual({ seuil_alerte: null });
    seuil.flush({});
    http.expectOne(`${API}/stocks`).flush({ stocks: [STOCK] });
  });

  it('libellés du journal', () => {
    expect(libelleMouvement({ type: 'sortie' })).toBe('Sortie (départ de livraison)');
    expect(libelleMouvement({ type: 'ajustement', motif_type: 'retrait_producteur' })).toBe('Ajustement : Retrait par le producteur');
  });

  it('catalogue : prix du producteur, total calculé, quantité limitée au disponible', () => {
    configurer();
    const fixture = TestBed.createComponent(CatalogueComponent);
    fixture.detectChanges();
    const produit = { stock_id: 5, produit: 'riz', quantite: 40, quantite_disponible: 40, prix_unitaire: 150, entrepot: {} };
    http.expectOne(`${API}/catalogue`).flush({ catalogue: [produit] });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('150 FCFA / kg');

    const comp = fixture.componentInstance;
    comp.ouvrirCommande(produit);
    comp.formCommande.setValue({ quantite: 41 });
    expect(comp.depasse(produit)).toBe(true);
    comp.formCommande.setValue({ quantite: 2.5 });
    expect(comp.montant(produit)).toBe(375);

    comp.passerCommande(produit);
    const req = http.expectOne(`${API}/commandes`);
    expect(req.request.body).toEqual({ stock_id: 5, quantite: 2.5 }); // jamais de prix saisi par l'acheteur
    req.flush({ commande: { numero_commande: 'CMD-1', quantite: 2.5, prix: 375 } }, { status: 201, statusText: 'Created' });
    http.expectOne(`${API}/catalogue`).flush({ catalogue: [] });
  });

  it('catalogue : une erreur de chargement n\'est pas présentée comme un catalogue vide', () => {
    configurer();
    const fixture = TestBed.createComponent(CatalogueComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/catalogue`).flush({}, { status: 500, statusText: 'Erreur' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Impossible de charger le catalogue');
    expect(fixture.nativeElement.textContent).not.toContain('Aucun produit');
  });

  it('récolte : prix au kilo obligatoire ; lot en entrepôt : seul le prix est envoyé', () => {
    configurer();
    const fixture = TestBed.createComponent(ProductionsComponent);
    fixture.detectChanges();
    const lot = { id: 3, code_tracabilite: 'LOT-2026-ABCDEF', type_culture: 'riz', date_recolte: '2026-06-01', quantite_estimee: 100, prix_unitaire: 150, statut: 'disponible', stocks: [{ id: 1 }] };
    http.expectOne(`${API}/productions`).flush({ productions: [lot] });
    const comp = fixture.componentInstance;

    comp.formCreation.patchValue({ type_culture: 'mil', date_recolte: '2026-06-01', quantite_estimee: 10 });
    expect(comp.formCreation.valid).toBe(false); // prix manquant

    comp.ouvrirEdition(lot);
    expect(comp.estVerrouille(lot)).toBe(true);
    expect(comp.formEdition.get('quantite_estimee')!.disabled).toBe(true);
    comp.formEdition.patchValue({ prix_unitaire: 180 });
    comp.sauvegarderEdition();
    const req = http.expectOne(`${API}/productions/3`);
    expect(req.request.body).toEqual({ prix_unitaire: 180 });
    req.flush({ production: { ...lot, prix_unitaire: 180 } });
  });

  it('demande de dépôt : dernière récolte préremplie (produit, quantité), récolte obligatoire', () => {
    configurer();
    const fixture = TestBed.createComponent(ReservationsComponent);
    fixture.detectChanges();
    http.match(() => true).forEach(r => {
      if (r.request.url.endsWith('/productions')) r.flush({ productions: [{ id: 9, code_tracabilite: 'LOT-1', type_culture: 'mil', quantite_estimee: 80, quantite_reelle: null, statut: 'en_attente' }] });
      else r.flush({ entrepots: [], reservations: [] });
    });
    const comp = fixture.componentInstance;
    comp.ouvrirFormulaire({ id: 2, capacite_disponible: 500 });
    // Lot 9 : dernière récolte préremplie, avec son produit et sa quantité
    expect(comp.form.value.production_id).toBe(9);
    expect(comp.form.value.produit).toBe('mil');
    expect(comp.form.value.quantite_reservee).toBe(80);

    comp.form.patchValue({ production_id: null });
    expect(comp.form.get('production_id')!.valid).toBe(false);
  });
});
