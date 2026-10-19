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

const INFOS = { numero_livraison: 'LIV-1', produit: 'riz', quantite: 10, date_depart: '2026-10-19 08:00:00', date_livraison: null, code_tracabilite: 'LOT-2026-TEST' };

function reponse(ageS = 5) {
  const maintenant = new Date('2026-10-19T10:00:00Z');
  return {
    livraison_id: 1, statut_livraison: 'en_cours', livraison: INFOS, genere_le: maintenant.toISOString(),
    origine: { nom: 'Entrepôt Nord', latitude: 14.79, longitude: -16.93 },
    destination: { adresse: 'Marché central, Dakar', latitude: 14.69, longitude: -17.44 },
    position: { latitude: 14.75, longitude: -17.1, timestamp: new Date(maintenant.getTime() - ageS * 1000).toISOString() },
  };
}
const erreur = (status: number, error: any = {}) => throwError(() => ({ status, error }));

describe('SuiviLivraisonComponent — états du suivi', () => {
  let carte: CarteFactice;
  let appels: number;

  function creer(...reponses: (() => any)[]) {
    appels = 0;
    carte = new CarteFactice();
    TestBed.configureTestingModule({
      imports: [SuiviLivraisonComponent],
      providers: [
        provideRouter([]),
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => '1' } } } },
        { provide: CARTE_SUIVI, useValue: () => carte },
        { provide: ApiService, useValue: { getPositionLivraison: () => reponses[Math.min(appels++, reponses.length - 1)]() } },
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
    const f = creer(() => erreur(404, { statut_livraison: 'livree', livraison: { ...INFOS, date_livraison: '2026-10-19 11:00:00' } }));
    f.detectChanges();
    vi.advanceTimersByTime(DELAI_ATTENTE_MS * 3);

    expect(appels).toBe(1);
    expect(carte.montages).toBe(0);
    expect(texte(f)).toContain('Livraison effectuée');
    expect(texte(f)).toContain('Livré le');
    expect(f.nativeElement.querySelector('a[href*="tracabilite?code=LOT-2026-TEST"]')).not.toBeNull();
  });

  it('livraison pas encore partie : le suivi reprend seul dès le départ', () => {
    const f = creer(() => erreur(404, { statut_livraison: 'en_attente', livraison: INFOS }), () => of(reponse()));
    f.detectChanges();
    expect(texte(f)).toContain('pas encore partie');

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
});
