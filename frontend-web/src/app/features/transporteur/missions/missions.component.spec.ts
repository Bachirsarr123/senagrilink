import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../../environments/environment';
import { INTERVALLE_ACTUALISATION_MS, TransporteurMissionsComponent } from './missions.component';

const API = environment.apiUrl;
const MISSION = {
  id: 7, numero_reservation: 'RES-20260601-ABCD', produit: 'oignon', quantite_reservee: 50, date_debut: '2026-06-02',
  statut: 'confirmee', transporteur_id: 3,
  producteur: { utilisateur: { prenom: 'Awa', nom: 'Diallo', telephone: '770000000' } },
  entrepot: { nom_entrepot: 'Entrepôt Nord', localisation: 'Thiès' },
};
const LIVRAISON = { id: 4, numero_livraison: 'LIV-1', statut: 'en_attente', origine: 'Entrepôt Nord', destination: 'Dakar', commande: { produit: 'riz', quantite: 10 } };

describe('TransporteurMissionsComponent — missions assignées', () => {
  let http: HttpTestingController;

  function creer() {
    TestBed.configureTestingModule({
      imports: [TransporteurMissionsComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(TransporteurMissionsComponent);
    fixture.detectChanges();
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }

  function repondre(reservations: any[], livraisons: any[] = []) {
    http.expectOne(`${API}/reservations`).flush({ reservations });
    http.expectOne(`${API}/livraisons`).flush({ livraisons });
  }

  afterEach(() => { http.verify(); vi.useRealTimers(); vi.restoreAllMocks(); });

  it('affiche la mission d\'acheminement assignée et la livraison', () => {
    const { fixture, el } = creer();
    expect(el.textContent).toContain('Chargement des missions');
    repondre([MISSION], [LIVRAISON]);
    fixture.detectChanges();

    expect(el.textContent).toContain('oignon — 50 kg');
    expect(el.textContent).toContain('RES-20260601-ABCD');
    expect(el.textContent).toContain('Awa Diallo');
    expect(el.textContent).toContain('Entrepôt Nord');
    expect(el.textContent).toContain('Démarrer la livraison');
    expect(el.textContent).not.toContain('Chargement des missions');
  });

  it('liste vide : message explicite, pas de chargement infini', () => {
    const { fixture, el } = creer();
    repondre([]);
    fixture.detectChanges();
    expect(el.textContent).toContain('Aucun acheminement en cours');
    expect(el.textContent).not.toContain('Chargement des missions');
  });

  it('serveur injoignable : message et bouton Réessayer qui recharge', () => {
    const { fixture, el } = creer();
    http.expectOne(`${API}/reservations`).error(new ProgressEvent('error'));
    http.match(`${API}/livraisons`); // requête jumelle annulée par l'échec
    fixture.detectChanges();
    expect(el.textContent).toContain('Serveur injoignable');

    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Réessayer')) as HTMLButtonElement).click();
    repondre([MISSION]);
    fixture.detectChanges();
    expect(el.textContent).toContain('RES-20260601-ABCD');
  });

  it('une mission assignée plus tard apparaît à l\'actualisation automatique', () => {
    vi.useFakeTimers();
    const { fixture, el } = creer();
    repondre([]);
    fixture.detectChanges();

    vi.advanceTimersByTime(INTERVALLE_ACTUALISATION_MS);
    repondre([MISSION]);
    fixture.detectChanges();
    expect(el.textContent).toContain('RES-20260601-ABCD');
  });

  it('retour de la PWA au premier plan : la liste est rechargée ; une mission retirée disparaît', () => {
    const { fixture, el } = creer();
    repondre([MISSION]);
    fixture.detectChanges();

    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    repondre([]);
    fixture.detectChanges();
    expect(el.textContent).not.toContain('RES-20260601-ABCD');
  });

  it('erreur réseau pendant une actualisation : les missions déjà affichées restent', () => {
    vi.useFakeTimers();
    const { fixture, el } = creer();
    repondre([MISSION]);
    vi.advanceTimersByTime(INTERVALLE_ACTUALISATION_MS);
    http.expectOne(`${API}/reservations`).error(new ProgressEvent('error'));
    http.match(`${API}/livraisons`); // requête jumelle annulée par l'échec
    fixture.detectChanges();
    expect(el.textContent).toContain('RES-20260601-ABCD');
    expect(el.textContent).toContain('Serveur injoignable');
  });

  it('signaler l\'arrivée : confirmation demandée, puis appel à l\'API ; refus = aucun appel', () => {
    const { fixture, el } = creer();
    repondre([MISSION]);
    fixture.detectChanges();
    const bouton = () => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Signaler l\'arrivée')) as HTMLButtonElement;

    vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    bouton().click();
    http.expectNone(`${API}/reservations/7/arrivee`);

    bouton().click();
    http.expectOne(r => r.method === 'PUT' && r.url === `${API}/reservations/7/arrivee`).flush({ message: 'ok' });
    repondre([{ ...MISSION, statut: 'arrivee_entrepot' }]);
    fixture.detectChanges();
    expect(el.textContent).toContain('Arrivée signalée');
    expect(el.textContent).toContain('Missions terminées');
  });
});
