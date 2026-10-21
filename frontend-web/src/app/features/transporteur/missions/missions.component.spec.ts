import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../../environments/environment';
import { INTERVALLE_ACTUALISATION_MS, TransporteurMissionsComponent } from './missions.component';

const API = environment.apiUrl;
const MISSION = {
  id: 7, numero_reservation: 'RES-20260601-ABCD', produit: 'oignon', quantite_reservee: 50, date_debut: '2026-06-02',
  statut: 'en_route', transporteur_id: 3, actions: ['arriver'],
  producteur: { utilisateur: { prenom: 'Awa', nom: 'Diallo', telephone: '770000000' } },
  entrepot: { nom_entrepot: 'Entrepôt Nord', localisation: 'Thiès', latitude: 14.79, longitude: -16.93 },
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

  it('arrivée à l\'entrepôt : confirmation demandée, puis étape envoyée ; refus = aucun appel', () => {
    const { fixture, el } = creer();
    repondre([MISSION]);
    fixture.detectChanges();
    const bouton = () => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Je suis arrivé à l\'entrepôt')) as HTMLButtonElement;
    // En route : l'itinéraire mène à l'entrepôt
    expect(el.querySelector('a.lien-itineraire')?.getAttribute('href')).toContain('destination=14.79,-16.93');

    vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    bouton().click();
    http.expectNone(`${API}/reservations/7/etape`);

    bouton().click();
    const req = http.expectOne(r => r.method === 'PUT' && r.url === `${API}/reservations/7/etape`);
    expect(req.request.body).toEqual({ etape: 'arrivee_entrepot' });
    req.flush({ message: 'ok' });
    repondre([{ ...MISSION, statut: 'arrivee_entrepot', actions: [] }]);
    fixture.detectChanges();
    expect(el.textContent).toContain('Étape enregistrée');
    expect(el.textContent).toContain('Missions terminées');
  });

  it('mission proposée : mise en avant, acceptée ou refusée avec un motif', () => {
    const { fixture, el } = creer();
    repondre([{ ...MISSION, statut: 'confirmee', actions: ['accepter_mission', 'refuser_mission'] }]);
    fixture.detectChanges();
    expect(el.textContent).toContain('Nouvelle mission à accepter');
    expect(el.textContent).not.toContain('Je suis arrivé');
    const bouton = (t: string) => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes(t)) as HTMLButtonElement;

    vi.spyOn(window, 'prompt').mockReturnValueOnce('Camion en panne');
    bouton('Refuser la mission').click();
    const refus = http.expectOne(`${API}/reservations/7/refuser-mission`);
    expect(refus.request.body).toEqual({ motif: 'Camion en panne' });
    refus.flush({});
    repondre([{ ...MISSION, statut: 'confirmee', actions: ['accepter_mission', 'refuser_mission'] }]);
    fixture.detectChanges();

    vi.spyOn(window, 'confirm').mockReturnValueOnce(true);
    bouton('Accepter la mission').click();
    http.expectOne(`${API}/reservations/7/accepter-mission`).flush({});
    repondre([{ ...MISSION, statut: 'prise_en_charge', actions: ['arriver_collecte'] }]);
  });
});
