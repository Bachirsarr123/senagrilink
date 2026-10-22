import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { AuthService } from '../core/auth/auth.service';
import { UtilisateursComponent } from './admin/utilisateurs/utilisateurs.component';
import { JournalComponent } from './admin/journal/journal.component';

/** Lot 6 — validation des comptes et journal d'audit, côté site. */
const API = environment.apiUrl;

describe('Lot 6 — site web', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); localStorage.clear(); vi.restoreAllMocks(); });

  it('inscription d\'un transporteur : compte en attente, aucune session ouverte', () => {
    const auth = TestBed.inject(AuthService);
    let reponse: any;
    auth.register({ role: 'transporteur' } as any).subscribe(r => (reponse = r));
    http.expectOne(`${API}/register`).flush({ message: 'Compte créé. Il sera activé après validation.', en_attente_validation: true, utilisateur: { id: 3 } }, { status: 201, statusText: 'Created' });
    expect(reponse.en_attente_validation).toBe(true);
    expect(localStorage.getItem('agri_token')).toBeNull();
    expect(auth.role()).toBeNull();
  });

  it('administrateur : valider ou refuser (motif) un compte en attente', () => {
    const f = TestBed.createComponent(UtilisateursComponent);
    f.detectChanges();
    http.expectOne(r => r.url === `${API}/admin/utilisateurs`).flush({ utilisateurs: [
      { id: 3, prenom: 'Binta', nom: 'Sarr', email: 'b@test.sn', role: 'transporteur', statut: 'en_attente_validation' },
      { id: 4, prenom: 'Ibra', nom: 'Ndiaye', email: 'i@test.sn', role: 'gestionnaire_entrepot', statut: 'en_attente_validation' },
    ] });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.textContent).toContain('En attente de validation');
    const boutons = (t: string) => Array.from(el.querySelectorAll('button')).filter(b => b.textContent?.trim() === t) as HTMLButtonElement[];

    vi.spyOn(window, 'confirm').mockReturnValue(true);
    boutons('Valider')[0].click();
    http.expectOne(r => r.method === 'PUT' && r.url === `${API}/admin/utilisateurs/3/valider`).flush({});
    f.detectChanges();
    vi.spyOn(window, 'prompt').mockReturnValue('Entrepôt inconnu');
    boutons('Refuser')[0].click();
    const refus = http.expectOne(r => r.method === 'PUT' && r.url === `${API}/admin/utilisateurs/4/refuser`);
    expect(refus.request.body).toEqual({ motif: 'Entrepôt inconnu' });
    refus.flush({});
    f.detectChanges();
    expect(el.textContent).toContain('Refusé : Entrepôt inconnu');
    expect(boutons('Bloquer')).toHaveLength(1);
  });

  it('journal d\'audit : entrées lisibles, filtres envoyés au serveur', () => {
    const f = TestBed.createComponent(JournalComponent);
    f.detectChanges();
    http.expectOne(r => r.url === `${API}/admin/journal`).flush({ entrees: [
      { id: 1, date: '2026-10-21T08:00:00Z', sujet_type: 'utilisateur', sujet_id: 3, action: 'echec_connexion', adresse_ip: '41.82.1.2', auteur: null },
    ], page: 1, pages: 1, total: 1 });
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Connexion échouée');
    expect(f.nativeElement.textContent).toContain('41.82.1.2');

    f.componentInstance.sujet = 'commande';
    f.componentInstance.action = 'annulation';
    f.componentInstance.charger(1);
    const req = http.expectOne(r => r.url === `${API}/admin/journal`);
    expect(req.request.params.get('sujet_type')).toBe('commande');
    expect(req.request.params.get('action')).toBe('annulation');
    req.flush({ entrees: [], page: 1, pages: 1, total: 0 });
  });
});
