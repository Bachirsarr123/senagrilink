import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { MesCommandesComponent } from './acheteur/mes-commandes/mes-commandes.component';
import { IncidentsComponent } from './entrepot/incidents/incidents.component';

/** Lot 3 — circuit entrepôt → acheteur et incidents, côté site. */
const API = environment.apiUrl;

describe('Lot 3 — site web', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => { http.verify(); vi.restoreAllMocks(); });

  const bouton = (el: HTMLElement, texte: string) =>
    Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes(texte)) as HTMLButtonElement | undefined;

  it('acheteur : code de réception affiché, boutons donnés par le serveur, réception confirmée', () => {
    const fixture = TestBed.createComponent(MesCommandesComponent);
    fixture.detectChanges();
    const commande = {
      id: 8, numero_commande: 'CMD-8', produit: 'riz', quantite: 10, statut: 'en_livraison', code_reception: '0427',
      livraison: { id: 2, statut: 'arrivee', transporteur: { utilisateur: { prenom: 'Moussa', nom: 'Fall', telephone: '771112233' } } },
      actions: ['confirmer_reception', 'declarer_anomalie'], prochaine_etape: { libelle: 'Réception à confirmer par l\'acheteur' }, incidents: [],
    };
    http.expectOne(`${API}/commandes/historique`).flush({ commandes: [commande] });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;

    expect(el.textContent).toContain('0427');
    expect(el.textContent).toContain('Moussa Fall');
    expect(el.textContent).toContain('Réception à confirmer');
    expect(bouton(el, 'Modifier')).toBeUndefined();
    expect(bouton(el, 'Demander l\'annulation')).toBeUndefined();

    vi.spyOn(window, 'confirm').mockReturnValue(true);
    bouton(el, 'J\'ai bien reçu ma commande')!.click();
    http.expectOne(r => r.method === 'PUT' && r.url === `${API}/commandes/8/reception`).flush({});
    http.expectOne(`${API}/commandes/historique`).flush({ commandes: [{ ...commande, statut: 'receptionnee', code_reception: null, actions: [] }] });
    fixture.detectChanges();
    expect(el.textContent).toContain('Réception confirmée');
    expect(el.textContent).not.toContain('0427');
  });

  it('gestionnaire : décision d\'incident, motif exigé quand il le faut', () => {
    const fixture = TestBed.createComponent(IncidentsComponent);
    fixture.detectChanges();
    const incident = {
      id: 5, sujet_type: 'commande', sujet_id: 8, type: 'demande_annulation', description: 'Plus besoin', statut: 'ouvert',
      sujet: { numero: 'CMD-8', produit: 'riz' }, declarant: { prenom: 'Awa', nom: 'Diop', role: 'acheteur_gros' },
      decisions: ['accepter_annulation', 'refuser_annulation'],
    };
    http.expectOne(`${API}/incidents`).flush({ incidents: [incident] });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    const comp = fixture.componentInstance;
    expect(el.textContent).toContain('Demande d\'annulation');

    comp.choix[5] = 'refuser_annulation';
    comp.decider(incident);
    expect(comp.erreur()).toContain('motif');
    http.expectNone(`${API}/incidents/5/resoudre`);

    vi.spyOn(window, 'confirm').mockReturnValue(true);
    comp.motif[5] = 'Déjà chargée';
    comp.decider(incident);
    const req = http.expectOne(`${API}/incidents/5/resoudre`);
    expect(req.request.body).toEqual({ decision: 'refuser_annulation', motif: 'Déjà chargée' });
    req.flush({});
    http.expectOne(`${API}/incidents`).flush({ incidents: [{ ...incident, statut: 'resolu', decision: 'refuser_annulation', motif_decision: 'Déjà chargée', decisions: [] }] });
    fixture.detectChanges();
    expect(el.textContent).toContain('Aucun incident ouvert');
    expect(el.textContent).toContain('Refuser l\'annulation');
  });
});
