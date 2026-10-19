import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../../environments/environment';
import { EntrepotReservationsComponent } from './reservations.component';

const API = environment.apiUrl;
const T1 = { id: 1, type_vehicule: 'camion', utilisateur: { prenom: 'Moussa', nom: 'Fall' } };
const T2 = { id: 2, type_vehicule: 'pick-up', utilisateur: { prenom: 'Ibou', nom: 'Sarr' } };
const EN_TRANSIT = {
  id: 5, numero_reservation: 'RES-5', produit: 'mil', quantite_reservee: 30, statut: 'confirmee', transporteur_id: 1, transporteur: T1,
  producteur: { utilisateur: { prenom: 'Awa', nom: 'Diallo' } },
  historique: [{ id: 9, action: 'assignation_transporteur', created_at: '2026-06-02T10:00:00', motif: null, utilisateur: { prenom: 'Gest', nom: 'Ion' } }],
};
const EN_ATTENTE = { id: 6, numero_reservation: 'RES-6', produit: 'riz', quantite_reservee: 10, statut: 'en_attente', transporteur_id: null, producteur: {} };

describe('EntrepotReservationsComponent — corrections du gestionnaire', () => {
  let http: HttpTestingController;

  function creer() {
    TestBed.configureTestingModule({ imports: [EntrepotReservationsComponent], providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(EntrepotReservationsComponent);
    fixture.detectChanges();
    http.expectOne(`${API}/reservations`).flush({ reservations: [EN_TRANSIT, EN_ATTENTE] });
    http.expectOne(`${API}/transporteurs`).flush({ transporteurs: [T1, T2] });
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const bouton = (texte: string) => Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes(texte)) as HTMLButtonElement;
    return { fixture, el, bouton };
  }

  afterEach(() => { http.verify(); vi.restoreAllMocks(); });

  it('affiche l\'historique et les actions de correction selon l\'état', () => {
    const { el, bouton } = creer();
    expect(el.textContent).toContain('Dernières corrections');
    expect(el.textContent).toContain('transporteur assigné par Gest Ion');
    expect(bouton('Changer de transporteur')).toBeTruthy();
    expect(bouton('Retirer la mission')).toBeTruthy();
    expect(bouton('Refuser')).toBeTruthy();
  });

  it('retirer la mission : abandon = aucun appel ; confirmation = DELETE avec le motif', () => {
    const { bouton } = creer();
    vi.spyOn(window, 'prompt').mockReturnValueOnce(null).mockReturnValueOnce('Erreur de choix');

    bouton('Retirer la mission').click();
    http.expectNone(`${API}/reservations/5/transporteur`);

    bouton('Retirer la mission').click();
    const req = http.expectOne(`${API}/reservations/5/transporteur`);
    expect(req.request.method).toBe('DELETE');
    expect(req.request.body).toEqual({ motif: 'Erreur de choix' });
    req.flush({ message: 'ok' });
    http.expectOne(`${API}/reservations`).flush({ reservations: [] });
  });

  it('refus : le motif est obligatoire (redemandé tant qu\'il est vide)', () => {
    const { bouton } = creer();
    const prompt = vi.spyOn(window, 'prompt').mockReturnValueOnce('  ').mockReturnValueOnce('Entrepôt plein');

    bouton('Refuser').click();
    expect(prompt).toHaveBeenCalledTimes(2);
    const req = http.expectOne(`${API}/reservations/6/refuser`);
    expect(req.request.body).toEqual({ motif: 'Entrepôt plein' });
    req.flush({ message: 'ok' });
    http.expectOne(`${API}/reservations`).flush({ reservations: [] });
  });

  it('réassignation : le transporteur actuel n\'est pas sélectionnable, le motif est transmis', () => {
    const { fixture, el, bouton } = creer();
    bouton('Changer de transporteur').click();
    fixture.detectChanges();

    const options = Array.from(el.querySelectorAll('select option')) as HTMLOptionElement[];
    expect(options.find(o => o.textContent?.includes('Moussa'))?.disabled).toBe(true);
    expect(el.textContent).toContain('La mission sera retirée à Moussa Fall');

    const comp = fixture.componentInstance;
    comp.formAssignation.setValue({ transporteur_id: 2, motif: 'Véhicule en panne' });
    comp.assigner();
    const req = http.expectOne(`${API}/reservations/5/assigner-transporteur`);
    expect(req.request.body).toEqual({ transporteur_id: 2, motif: 'Véhicule en panne' });
    req.flush({ message: 'Mission réassignée au nouveau transporteur.' });
    http.expectOne(`${API}/reservations`).flush({ reservations: [] });
  });

  it('fermer une saisie en cours demande confirmation', () => {
    const { fixture, bouton } = creer();
    bouton('Changer de transporteur').click();
    fixture.componentInstance.formAssignation.get('motif')!.setValue('x');
    fixture.componentInstance.formAssignation.markAsDirty();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

    fixture.componentInstance.demanderFermeture();
    expect(confirm).toHaveBeenCalled();
    expect(fixture.componentInstance.reservationAAssigner()).not.toBeNull();
  });
});
