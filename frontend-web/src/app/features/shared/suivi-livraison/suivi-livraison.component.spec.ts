import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { Subject, of, throwError } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { CARTE_SUIVI, CarteSuivi } from '../../../shared/suivi/carte-suivi';
import { DELAI_ATTENTE_MS, DELAI_SUIVI_MS, SuiviLivraisonComponent } from './suivi-livraison.component';

/** Carte factice : Leaflet n'est pas exécuté en test, on vérifie ce qui lui est confié. */
class CarteFactice implements CarteSuivi {
  montages = 0;
  affichages: any[] = [];
  monter() { this.montages++; }
  afficher(points: any) { this.affichages.push(points); }
  vueEnsemble() {}
  centrerVehicule() {}
  detruire() {}
}

const DETAILS = { produit: 'riz', quantite: 10, parti_le: '2026-10-19T08:00:00Z', arrive_le: null, code_tracabilite: 'LOT-2026-TEST' };

function reponse(ageS = 5, extra: any = {}) {
  const maintenant = new Date('2026-10-19T10:00:00Z');
  return {
    sujet_type: 'livraison', sujet_id: 1, numero: 'LIV-1', statut: 'en_cours', en_route: true, details: DETAILS, genere_le: maintenant.toISOString(),
    conducteur: { nom: 'Moussa Fall', telephone: '771112233' },
    origine: { nom: 'Entrepôt Nord', latitude: 14.79, longitude: -16.93 },
    destination: { adresse: 'Marché central, Dakar', latitude: 14.69, longitude: -17.44 },
    position: { latitude: 14.75, longitude: -17.1, timestamp: new Date(maintenant.getTime() - ageS * 1000).toISOString() },
    trace: [[14.79, -16.93], [14.77, -17.0]], trajet: null, arrivee_estimee_le: null, distance_restante_km: null, estimation_routiere: false,
    ...extra,
  };
}
/** Hors trajet : aucune position transmise (Q11). */
const horsTrajet = (statut: string, details: any = {}) => of({ ...reponse(), statut, en_route: false, position: null, trace: [], details: { ...DETAILS, ...details } });
const erreur = (status: number, error: any = {}) => throwError(() => ({ status, error }));

describe('SuiviLivraisonComponent — états du suivi', () => {
  let carte: CarteFactice;
  let appels: number;
  let dernierAppel: [string, number];

  function creer(...reponses: (() => any)[]) {
    appels = 0;
    carte = new CarteFactice();
    TestBed.configureTestingModule({
      imports: [SuiviLivraisonComponent],
      providers: [
        provideRouter([]),
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: (k: string) => (k === 'id' ? '1' : null) } } } },
        { provide: CARTE_SUIVI, useValue: () => carte },
        { provide: ApiService, useValue: {
          getConfigurationCarte: () => of({ tuiles_url: 'https://tuiles.test/{z}/{x}/{y}.png', attribution: '© Test' }),
          getSuivi: (type: string, id: number) => { dernierAppel = [type, id]; return reponses[Math.min(appels++, reponses.length - 1)](); },
        } },
      ],
    });
    const fixture = TestBed.createComponent(SuiviLivraisonComponent);
    fixture.detectChanges();
    return fixture;
  }
  const texte = (f: any) => (f.nativeElement as HTMLElement).textContent ?? '';

  beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date('2026-10-19T10:00:00Z')); });
  afterEach(() => vi.useRealTimers());

  it('premier chargement : carte montée une fois, position en direct, aucun faux message d\'erreur', () => {
    const f = creer(() => of(reponse()));
    f.detectChanges();

    expect(carte.montages).toBe(1);
    expect(carte.affichages[0].vehicule).toEqual({ latitude: 14.75, longitude: -17.1 });
    expect(carte.affichages[0].destination).toMatchObject({ latitude: 14.69, longitude: -17.44 });
    expect(texte(f)).toContain('En direct');
    expect(texte(f)).not.toContain('indisponible');
    expect(texte(f)).toContain('jusqu\'à la destination');
    expect(texte(f)).toContain('Moussa Fall');
    expect(dernierAppel).toEqual(['livraison', 1]);
    expect(carte.affichages[0].trace).toEqual([[14.79, -16.93], [14.77, -17.0]]);
    expect(f.nativeElement.querySelector('a[href*="tracabilite?code=LOT-2026-TEST"]')).not.toBeNull();
  });

  it('une position ancienne est présentée comme « dernière position connue », jamais « en direct »', () => {
    const f = creer(() => of(reponse(12 * 60)));
    f.detectChanges();
    expect(texte(f)).toContain('Dernière position connue');
    expect(texte(f)).toContain('il y a 12 min');
    expect(texte(f)).not.toContain('En direct');
  });

  it('rafraîchit toutes les 10 s sans remonter la carte', () => {
    const f = creer(() => of(reponse()));
    vi.advanceTimersByTime(DELAI_SUIVI_MS * 2);
    f.detectChanges();
    expect(appels).toBe(3);
    expect(carte.montages).toBe(1);
    expect(carte.affichages).toHaveLength(3);
  });

  it('livraison terminée : plus de position, état clair, lien vers la traçabilité, suivi arrêté', () => {
    const f = creer(() => horsTrajet('livree', { arrive_le: '2026-10-19T11:00:00Z' }));
    f.detectChanges();
    vi.advanceTimersByTime(DELAI_ATTENTE_MS * 3);

    expect(appels).toBe(1);
    expect(carte.montages).toBe(0);
    expect(texte(f)).toContain('Trajet terminé');
    expect(texte(f)).toContain('Arrivé le');
    expect(f.nativeElement.querySelector('a[href*="tracabilite?code=LOT-2026-TEST"]')).not.toBeNull();
  });

  it('livraison pas encore partie : le suivi reprend seul dès le départ', () => {
    const f = creer(() => horsTrajet('chargee'), () => of(reponse()));
    f.detectChanges();
    expect(texte(f)).toContain('Pas encore parti');

    vi.advanceTimersByTime(DELAI_ATTENTE_MS);
    f.detectChanges();
    expect(appels).toBe(2);
    expect(texte(f)).toContain('En direct');
  });

  it('coupure réseau : la dernière position reste, signalée comme ancienne', () => {
    const f = creer(() => of(reponse()), () => erreur(0));
    vi.advanceTimersByTime(DELAI_SUIVI_MS);
    f.detectChanges();

    expect(texte(f)).toContain('Connexion au serveur perdue');
    expect(texte(f)).toContain('Dernière position connue');
    expect(carte.montages).toBe(1);
  });

  it('accès refusé : message dédié, aucune nouvelle requête', () => {
    const f = creer(() => erreur(403, { message: 'Accès non autorisé à cette position.' }));
    f.detectChanges();
    vi.advanceTimersByTime(DELAI_ATTENTE_MS * 2);
    expect(texte(f)).toContain('Accès refusé');
    expect(appels).toBe(1);
  });

  it('chargement : indicateur sans message d\'erreur tant que la réponse n\'est pas arrivée', () => {
    const attente = new Subject<any>();
    const f = creer(() => attente);
    f.detectChanges();
    expect(texte(f)).toContain('Chargement du suivi');
    expect(texte(f)).not.toContain('indisponible');
  });

  it('arrivée estimée et trajet par la route, calculés par le serveur', () => {
    const f = creer(() => of(reponse(5, {
      arrivee_estimee_le: '2026-10-19T10:35:00Z', distance_restante_km: 42.3, estimation_routiere: true,
      trajet: [[14.73, -17.2], [14.69, -17.44]],
    })));
    f.detectChanges();
    expect(texte(f)).toMatch(/Arrivée vers \d\d:35/);
    expect(texte(f)).toContain('dans 35 min');
    expect(texte(f)).toContain('estimation par la route');
    expect(texte(f)).toContain('Trajet par la route');
    expect(carte.affichages[0].trajet).toEqual([[14.73, -17.2], [14.69, -17.44]]);
  });
});
