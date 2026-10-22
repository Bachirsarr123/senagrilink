import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { environment } from '../../environments/environment';
import { CARTE_SAISIE, CarteSaisie, ChoixPositionComponent, Position } from '../shared/position/choix-position';
import { PositionAppareilService } from '../shared/position/position-appareil';
import { ProducteurReservationsComponent } from './producteur/reservations/reservations.component';
import { CatalogueComponent } from './acheteur/catalogue/catalogue.component';

/** Lot 8 — lieux pris automatiquement, modifiables sur la carte. */
const API = environment.apiUrl;
const ICI: Position = { latitude: 14.75, longitude: -17.38 };

class FausseCarte implements CarteSaisie {
  static derniere: FausseCarte;
  surClic!: (p: Position) => void;
  places: Position[] = [];
  constructor() { FausseCarte.derniere = this; }
  monter(_: HTMLElement, depart: Position | null, surClic: (p: Position) => void): void { this.surClic = surClic; if (depart) this.places.push(depart); }
  placer(p: Position): void { this.places.push(p); }
  detruire(): void {}
}

describe('Lot 8 — site web', () => {
  let http: HttpTestingController;
  let position: Position | null;

  function configurer(role = 'producteur', profil: any = {}): void {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Sarr', prenom: 'Ali', role, ...profil }));
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
        { provide: CARTE_SAISIE, useValue: () => new FausseCarte() },
        { provide: PositionAppareilService, useValue: {
          actuelle: () => Promise.resolve(position), fondDeCarte: () => of({}),
          lieuHabituel: () => (profil.producteur ?? profil.acheteur_gros ?? null),
        } }],
    });
    http = TestBed.inject(HttpTestingController);
  }

  const attendre = () => new Promise(r => setTimeout(r));

  beforeEach(() => { position = ICI; });
  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  it('carte de position : la position de l\'appareil est prise d\'elle-même, puis corrigeable', async () => {
    configurer();
    const fixture = TestBed.createComponent(ChoixPositionComponent);
    fixture.componentRef.setInput('auto', true);
    const emis: (Position | null)[] = [];
    fixture.componentInstance.changement.subscribe(p => emis.push(p));
    fixture.detectChanges();
    await attendre(); fixture.detectChanges();
    expect(emis[0]).toEqual(ICI);
    expect(fixture.nativeElement.textContent).toContain('Votre position actuelle');

    FausseCarte.derniere.surClic({ latitude: 14.8, longitude: -17.3 });
    fixture.detectChanges();
    expect(emis[1]).toEqual({ latitude: 14.8, longitude: -17.3 });
    expect(fixture.nativeElement.textContent).not.toContain('Votre position actuelle');
  });

  it('autorisation refusée : rien n\'est redemandé, le lieu se place sur la carte', async () => {
    position = null;
    configurer();
    const fixture = TestBed.createComponent(ChoixPositionComponent);
    fixture.componentRef.setInput('auto', true);
    fixture.detectChanges();
    await attendre(); fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('cliquez sur la carte à l\'endroit exact');
  });

  it('dépôt : entrepôts les plus proches d\'abord, lieu de prise en charge envoyé', async () => {
    configurer();
    const fixture = TestBed.createComponent(ProducteurReservationsComponent);
    fixture.detectChanges();
    http.expectOne(r => r.url === `${API}/entrepots/disponibles` && !r.params.has('latitude')).flush({ entrepots: [] });
    http.expectOne(`${API}/reservations`).flush({ reservations: [] });
    http.expectOne(`${API}/productions`).flush({ productions: [{ id: 5, type_culture: 'mil', quantite_estimee: 40, statut: 'disponible' }] });
    await attendre();
    const proches = http.expectOne(r => r.url === `${API}/entrepots/disponibles` && r.params.get('latitude') === '14.75');
    proches.flush({ entrepots: [{ id: 2, nom_entrepot: 'Thiès', capacite_disponible: 500, distance_km: 3.2 }] });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('à 3.2 km');
    expect(fixture.nativeElement.textContent).toContain('Les plus proches de vous d\'abord');

    const comp = fixture.componentInstance;
    comp.ouvrirFormulaire({ id: 2, nom_entrepot: 'Thiès', capacite_disponible: 500 });
    comp.form.patchValue({ production_id: 5, mode_transport: 'transporteur', quantite_reservee: 10, date_debut: '2026-11-01' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Où le transporteur vient-il chercher la marchandise ?');
    comp.reserver();
    const envoi = http.expectOne(`${API}/reservations`);
    expect([envoi.request.body.collecte_latitude, envoi.request.body.collecte_longitude]).toEqual([14.75, -17.38]);
    envoi.flush({ reservation: { id: 9 } });
    http.match(() => true).forEach(r => r.flush({ entrepots: [], reservations: [] }));
  });

  it('commande : livrée à la position actuelle, ou au lieu corrigé sur la carte', async () => {
    configurer('acheteur_gros', { acheteur_gros: { latitude: 14.6, longitude: -17.5 } });
    const fixture = TestBed.createComponent(CatalogueComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/catalogue`).flush({ catalogue: [] });
    await attendre(); fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('à votre position actuelle');

    const comp: any = fixture.componentInstance;
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    comp.panier.set([{ stock_id: 4, produit: 'riz', quantite: 5, prix_unitaire: 150 }]);
    comp.lieuChoisi({ latitude: 14.7, longitude: -17.45 });
    comp.commanderPanier();
    const envoi = http.expectOne(`${API}/commandes`);
    expect(envoi.request.body).toEqual({ lignes: [{ stock_id: 4, quantite: 5 }], livraison_latitude: 14.7, livraison_longitude: -17.45 });
    envoi.flush({ message: 'Panier commandé.', commandes: [] });
    http.match(() => true).forEach(r => r.flush({ catalogue: [] }));
  });
});
