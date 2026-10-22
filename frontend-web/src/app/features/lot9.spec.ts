import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { CARTE_CONDUITE, CarteConduite, ConduiteComponent } from './shared/conduite/conduite.component';
import { AFaireComponent } from './shared/a-faire/a-faire.component';

/** Lot 9 — guidage dans le site et actions d'un appui. */
const API = environment.apiUrl;

class FausseCarte implements CarteConduite {
  static derniere: FausseCarte;
  trajets: [number, number][][] = [];
  constructor() { FausseCarte.derniere = this; }
  monter(): void {}
  afficher(_moi: any, trajet: [number, number][]): void { this.trajets.push(trajet); }
  detruire(): void {}
}

const NAV = {
  sujet_type: 'livraison', sujet_id: 5, numero: 'LIV-5', statut: 'prise_en_charge', vers: 'origine',
  cible: { nom: 'Entrepôt Thiès', adresse: 'Route de Dakar', latitude: 14.79, longitude: -16.93 },
  actions: ['charger', 'declarer_incident'], proche: false,
  itineraire: {
    km: 2.3, secondes: 300, routier: true, arrivee_vers: '2026-10-22T10:05:00Z',
    points: [[14.80, -16.95], [14.795, -16.94], [14.79, -16.93]],
    etapes: [
      { instruction: 'Partez par Avenue Senghor', type: 'depart', debut: 0, fin: 1 },
      { instruction: 'Tournez à droite sur Route de Dakar', type: 'droite', debut: 1, fin: 2 },
      { instruction: 'Arrivée à destination', type: 'arrivee', debut: 2, fin: 2 },
    ],
  },
};

describe('Lot 9 — site web', () => {
  let http: HttpTestingController;
  let dit: string[];
  let surPosition: ((p: any) => void) | null;

  function configurer(role: string, params: Record<string, string> = {}): void {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Fall', prenom: 'Awa', role }));
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
        { provide: CARTE_CONDUITE, useValue: () => new FausseCarte() },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: (k: string) => params[k] ?? null } } } }],
    });
    http = TestBed.inject(HttpTestingController);
  }

  beforeEach(() => {
    dit = [];
    surPosition = null;
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: {
      watchPosition: (ok: (p: any) => void) => { surPosition = ok; return 1; }, clearWatch: () => {},
    } });
    (globalThis as any).SpeechSynthesisUtterance = class { lang = ''; constructor(public text: string) {} };
    (globalThis as any).speechSynthesis = { speak: (u: any) => dit.push(u.text), cancel: () => {} };
    ConduiteComponent.delaiAnnulationMs = 50;
    AFaireComponent.delaiAnnulationMs = 50;
  });
  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  const attendre = (ms = 120) => new Promise(r => setTimeout(r, ms));

  it('conduite : chemin sur la carte, consigne en gros et à voix haute, gros bouton avec annulation', async () => {
    configurer('transporteur', { type: 'livraison', id: '5' });
    const fixture = TestBed.createComponent(ConduiteComponent);
    fixture.detectChanges();
    surPosition!({ coords: { latitude: 14.80, longitude: -16.95 } });
    const req = http.expectOne(r => r.url === `${API}/navigation/livraison/5`);
    expect(req.request.params.get('latitude')).toBe('14.8');
    req.flush(NAV);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.textContent).toContain('Tournez à droite sur Route de Dakar');
    expect(el.textContent).toContain('Entrepôt Thiès');
    expect(el.textContent).toContain('5 min · 2,3 km');
    expect(FausseCarte.derniere.trajets.at(-1)!.length).toBe(3);
    expect(dit.at(-1)).toMatch(/^Dans .*Tournez à droite sur Route de Dakar$/);

    const bouton = () => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Marchandise chargée')) as HTMLButtonElement;
    // Appui puis « Annuler » : rien n'est envoyé
    ConduiteComponent.delaiAnnulationMs = 2000;
    bouton().click(); fixture.detectChanges();
    expect(el.textContent).toContain('« Marchandise chargée » dans');
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Annuler')) as HTMLButtonElement).click();
    fixture.detectChanges();
    http.expectNone(`${API}/livraisons/5/etape`);

    // Appui confirmé après le délai
    ConduiteComponent.delaiAnnulationMs = 50;
    bouton().click();
    await attendre();
    const etape = http.expectOne(`${API}/livraisons/5/etape`);
    expect(etape.request.body).toEqual({ etape: 'chargee' });
    etape.flush({ message: 'Chargement enregistré.' });
    http.expectOne(r => r.url === `${API}/navigation/livraison/5`).flush({ ...NAV, statut: 'chargee', vers: 'destination', actions: ['partir'] });
    fixture.detectChanges();
    expect(el.textContent).toContain('Chargement enregistré.');
    expect(el.textContent).toContain("Je pars vers l'acheteur");
    fixture.destroy();
  });

  it('À faire : confier au transporteur proposé d\'un appui ; étape de transport = Conduire', async () => {
    configurer('gestionnaire_entrepot');
    const fixture = TestBed.createComponent(AFaireComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/a-faire`).flush({ elements: [
      { categorie: 'commande', sujet_type: 'commande', sujet_id: 8, numero: 'CMD-8', titre: 'riz — 10 kg', etape: 'Organiser la livraison',
        action: 'organiser_livraison', transporteur_propose: { id: 4, nom: 'Awa Fall', distance_km: 1.5 } },
      { categorie: 'livraison', sujet_type: 'livraison', sujet_id: 5, numero: 'LIV-5', titre: 'riz', etape: 'Charger', action: 'charger' },
    ] });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('a[href="/conduite/livraison/5"]')?.textContent).toContain('Conduire');
    const confier = Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Confier à Awa Fall (à 1,5 km)')) as HTMLButtonElement;
    confier.click();
    await attendre();
    const post = http.expectOne(`${API}/livraisons`);
    expect(post.request.body).toEqual({ commande_id: 8, transporteur_id: 4 });
    expect(post.request.headers.get('Idempotency-Key')).toBeTruthy();
    post.flush({ message: 'Livraison organisée.' });
    http.expectOne(`${API}/a-faire`).flush({ elements: [] });
  });
});
