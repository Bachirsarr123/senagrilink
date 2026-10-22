import { provideRouter } from '@angular/router';
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
const LIVRAISON = {
  id: 4, numero_livraison: 'LIV-1', statut: 'en_attente', origine: 'Entrepôt Nord', destination: 'Dakar', commande_id: 9, incidents: [],
  commande: { produit: 'riz', quantite: 10, acheteur: { latitude: 14.7, longitude: -17.4, utilisateur: { prenom: 'Awa', nom: 'Diop', telephone: '770000000' } },
    stock: { entrepot: { nom_entrepot: 'Entrepôt Nord', latitude: 14.79, longitude: -16.93 } } },
  actions: ['accepter_mission', 'refuser_mission'], prochaine_etape: { libelle: 'Mission à accepter par le transporteur' },
};

describe('TransporteurMissionsComponent — missions assignées', () => {
  let http: HttpTestingController;

  function creer() {
    TestBed.configureTestingModule({
      imports: [TransporteurMissionsComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
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
    expect(el.textContent).toContain('Nouvelle livraison à accepter');
    expect(el.textContent).toContain('Awa Diop');
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
    // En route : guidé dans le site jusqu'à l'entrepôt (lot 9)
    expect(el.querySelector('a.lien-itineraire')?.getAttribute('href')).toBe('/conduite/reservation/7');
    expect(el.querySelector('a.lien-itineraire')?.textContent).toContain("Conduire jusqu'à l'entrepôt");

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

  it('livraison : guidée dans le site jusqu\'à l\'entrepôt, puis remise avec le code à 4 chiffres', () => {
    const { fixture, el } = creer();
    repondre([], [{ ...LIVRAISON, statut: 'prise_en_charge', actions: ['charger', 'declarer_incident'] }]);
    fixture.detectChanges();
    expect(el.querySelector('a.lien-itineraire')?.getAttribute('href')).toBe('/conduite/livraison/4');
    expect(el.querySelector('a.lien-itineraire')?.textContent).toContain("Conduire jusqu'à l'entrepôt");

    fixture.componentInstance.charger();
    http.expectOne(`${API}/reservations`).flush({ reservations: [] });
    http.expectOne(`${API}/livraisons`).flush({ livraisons: [{ ...LIVRAISON, statut: 'arrivee', actions: ['confirmer_par_code', 'declarer_incident'] }] });
    fixture.detectChanges();
    expect(el.querySelector('a.lien-itineraire')).toBeNull(); // arrivé : plus de trajet

    const champ = el.querySelector('#code-4') as HTMLInputElement;
    const remettre = Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Remettre avec le code')) as HTMLButtonElement;
    champ.value = '12';
    remettre.click();
    http.expectNone(`${API}/livraisons/4/remise`);
    fixture.detectChanges();
    expect(el.textContent).toContain('4 chiffres');

    champ.value = '0427';
    remettre.click();
    const req = http.expectOne(`${API}/livraisons/4/remise`);
    expect(req.request.body).toEqual({ code: '0427' });
    req.flush({});
    repondre([], []);
  });

  it('incident : type et description obligatoires, envoyé à l\'entrepôt', () => {
    const { fixture, el } = creer();
    repondre([], [{ ...LIVRAISON, statut: 'en_cours', actions: ['arriver', 'declarer_incident'] }]);
    fixture.detectChanges();
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Déclarer un incident')) as HTMLButtonElement).click();
    fixture.detectChanges();
    const envoyer = () => (Array.from(el.querySelectorAll('.modal-box button')).find(b => b.textContent?.includes('Envoyer')) as HTMLButtonElement).click();

    envoyer();
    fixture.detectChanges();
    expect(el.textContent).toContain('Choisissez le type');
    http.expectNone(`${API}/incidents`);

    const select = el.querySelector('#type-incident') as HTMLSelectElement;
    select.value = 'panne';
    select.dispatchEvent(new Event('change'));
    const texte = el.querySelector('#description-incident') as HTMLTextAreaElement;
    texte.value = 'Pneu crevé';
    texte.dispatchEvent(new Event('input'));
    envoyer();
    const req = http.expectOne(`${API}/incidents`);
    expect(req.request.body).toEqual({ sujet_type: 'livraison', sujet_id: 4, type: 'panne', description: 'Pneu crevé' });
    expect(req.request.headers.get('Idempotency-Key')).toBeTruthy();
    req.flush({}, { status: 201, statusText: 'Created' });
    repondre([], []);
    fixture.detectChanges();
    expect(el.querySelector('.modal-box')).toBeNull();
  });
});
