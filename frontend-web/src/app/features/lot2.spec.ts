import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { construireChronologie } from '../shared/suivi/chronologie';
import { CARTE_SAISIE, CarteSaisie, ChoixPositionComponent, Position } from '../shared/position/choix-position';
import { ProducteurReservationsComponent } from './producteur/reservations/reservations.component';
import { ProfilComponent } from './shared/profil/profil.component';

/** Lot 2 — circuit « récolte → entrepôt » côté site. */
const API = environment.apiUrl;

class FausseCarte implements CarteSaisie {
  static derniere: FausseCarte;
  surClic!: (p: Position) => void;
  places: Position[] = [];
  constructor() { FausseCarte.derniere = this; }
  monter(_: HTMLElement, depart: Position | null, surClic: (p: Position) => void): void { this.surClic = surClic; if (depart) this.places.push(depart); }
  placer(p: Position): void { this.places.push(p); }
  detruire(): void {}
}

describe('Lot 2 — site web', () => {
  let http: HttpTestingController;

  function configurer(): void {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), { provide: CARTE_SAISIE, useValue: () => new FausseCarte() }],
    });
    http = TestBed.inject(HttpTestingController);
  }

  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  describe('demande de dépôt du producteur', () => {
    const RESA = {
      id: 3, numero_reservation: 'RES-3', produit: 'mil', quantite_reservee: 40, statut: 'confirmee', mode_transport: 'producteur',
      entrepot: { nom_entrepot: 'Nord' }, actions: ['partir', 'annuler'], prochaine_etape: { libelle: 'Le producteur apporte la marchandise' },
    };

    function creer(reservations: any[] = [RESA]) {
      configurer();
      const fixture = TestBed.createComponent(ProducteurReservationsComponent);
      fixture.detectChanges();
      http.match(() => true).forEach(r => {
        if (r.request.url.endsWith('/productions')) r.flush({ productions: [{ id: 9, code_tracabilite: 'LOT-1', type_culture: 'mil', quantite_estimee: 80, statut: 'en_attente' }] });
        else if (r.request.url.endsWith('/reservations')) r.flush({ reservations });
        else r.flush({ entrepots: [] });
      });
      fixture.detectChanges();
      const el = fixture.nativeElement as HTMLElement;
      const bouton = (t: string) => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes(t)) as HTMLButtonElement;
      return { fixture, el, bouton, comp: fixture.componentInstance };
    }

    it('le mode de transport est obligatoire et envoyé', () => {
      const { comp } = creer([]);
      comp.ouvrirFormulaire({ id: 2, capacite_disponible: 500 });
      comp.form.patchValue({ production_id: 9, quantite_reservee: 40, date_debut: '2026-10-22' });
      comp.reserver();
      http.expectNone(`${API}/reservations`);
      expect(comp.form.get('mode_transport')!.invalid).toBe(true);

      comp.form.patchValue({ mode_transport: 'producteur' });
      comp.reserver();
      const req = http.expectOne(r => r.method === 'POST' && r.url === `${API}/reservations`);
      expect(req.request.body.mode_transport).toBe('producteur');
      expect(req.request.body.entrepot_id).toBe(2);
      req.flush({}, { status: 201, statusText: 'Created' });
      http.match(() => true).forEach(r => r.flush({ entrepots: [], reservations: [] }));
    });

    it('il livre lui-même : boutons d\'après les actions du serveur, étape envoyée après confirmation', () => {
      const { fixture, el, bouton } = creer();
      expect(el.textContent).toContain('Livré par le producteur');
      expect(el.textContent).toContain('Le producteur apporte la marchandise');
      expect(bouton('Je suis arrivé')).toBeUndefined();

      vi.spyOn(window, 'confirm').mockReturnValue(true);
      bouton('Je pars vers l\'entrepôt').click();
      const req = http.expectOne(`${API}/reservations/3/etape`);
      expect(req.request.body).toEqual({ etape: 'en_route' });
      req.flush({ message: 'Départ enregistré. L\'entrepôt a été prévenu.' });
      http.expectOne(`${API}/reservations`).flush({ reservations: [{ ...RESA, statut: 'en_route', actions: ['arriver'] }] });
      fixture.detectChanges();
      expect(el.textContent).toContain('Départ enregistré');
      expect(bouton('Je suis arrivé à l\'entrepôt')).toBeTruthy();
      expect(bouton('Annuler le dépôt')).toBeUndefined();
    });
  });

  it('choix de la position : un clic place le point (arrondi à 7 décimales) et le transmet', () => {
    configurer();
    const fixture = TestBed.createComponent(ChoixPositionComponent);
    const emis: (Position | null)[] = [];
    fixture.componentInstance.changement.subscribe(p => emis.push(p));
    fixture.detectChanges();

    FausseCarte.derniere.surClic({ latitude: 14.123456789, longitude: -17.987654321 });
    fixture.detectChanges();
    expect(emis[0]).toEqual({ latitude: 14.1234568, longitude: -17.9876543 });
    expect(fixture.nativeElement.textContent).toContain('Position enregistrée');

    fixture.componentInstance.effacer();
    expect(emis[1]).toBeNull();
  });

  it('profil du producteur : la position choisie est enregistrée', () => {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Diallo', prenom: 'Awa', email: 'a@test.sn', role: 'producteur', producteur: { latitude: 14.7, longitude: -17.4 } }));
    configurer();
    const fixture = TestBed.createComponent(ProfilComponent);
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Position de votre exploitation');
    expect(FausseCarte.derniere.places[0]).toEqual({ latitude: 14.7, longitude: -17.4 });

    FausseCarte.derniere.surClic({ latitude: 14.8, longitude: -16.9 });
    fixture.componentInstance.sauvegarder();
    const req = http.expectOne(`${API}/profile`);
    expect([req.request.body.latitude, req.request.body.longitude]).toEqual([14.8, -16.9]);
    req.flush({ utilisateur: { id: 1, nom: 'Diallo', prenom: 'Awa', email: 'a@test.sn', role: 'producteur' } });
  });

  it('chronologie : étapes de l\'acheminement datées ; refus de réception en alerte', () => {
    const etapes = construireChronologie({
      etape_production: { type_culture: 'mil', date_recolte: '2026-10-01', quantite_estimee: 80 },
      etapes_acheminement: [{
        entrepot: { nom: 'Nord' }, statut: 'en_route', quantite_prevue: 40, transporteur: { prenom: 'Ibra', nom: 'Test' },
        etapes: [
          { action: 'creation', libelle: 'Dépôt demandé', date: '2026-10-02T08:00:00Z' },
          { action: 'acceptation_mission', libelle: 'Mission acceptée par le transporteur', date: '2026-10-02T09:00:00Z' },
          { action: 'en_route', libelle: 'En route vers l\'entrepôt', date: '2026-10-02T10:00:00Z' },
        ],
      }, {
        entrepot: { nom: 'Sud' }, statut: 'reception_refusee', quantite_prevue: 10,
        etapes: [{ action: 'refus_reception', libelle: 'Réception refusée', date: '2026-10-03T10:00:00Z' }],
      }],
    });

    expect(etapes.map(e => e.titre)).toEqual(['Récolte', 'Dépôt demandé', 'Mission acceptée par le transporteur', 'En route vers l\'entrepôt', 'Réception refusée']);
    expect(etapes[3].etat).toBe('actuel');
    expect(etapes[3].detail).toContain('Ibra Test');
    expect(etapes[4].etat).toBe('alerte');
  });
});
