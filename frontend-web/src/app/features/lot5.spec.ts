import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { AFaireComponent } from './shared/a-faire/a-faire.component';
import { FicheCommandeComponent } from './shared/fiche-commande/fiche-commande.component';
import { ExceptionsComponent } from './admin/dashboard/exceptions.component';

/** Lot 5 — écrans par rôle, côté site. */
const API = environment.apiUrl;

describe('Lot 5 — site web', () => {
  let http: HttpTestingController;

  function configurer(role = 'gestionnaire_entrepot', id = '8') {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Ndiaye', prenom: 'Ibra', role }));
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => id } } } }] });
    http = TestBed.inject(HttpTestingController);
  }

  afterEach(() => { http.verify(); localStorage.clear(); vi.restoreAllMocks(); });

  it('À faire : éléments du serveur, urgents signalés, lien vers l\'endroit où agir', () => {
    configurer();
    const f = TestBed.createComponent(AFaireComponent);
    f.detectChanges();
    http.expectOne(`${API}/a-faire`).flush({ elements: [
      { categorie: 'incident', sujet_type: 'livraison', sujet_id: 2, incident_id: 5, numero: 'LIV-2', titre: 'Moteur', etape: 'Incident à décider : panne', urgence: 'haute', en_retard: false, depuis: '2026-10-21T08:00:00Z' },
      { categorie: 'commande', sujet_type: 'commande', sujet_id: 8, numero: 'CMD-8', titre: 'riz — 10 kg', etape: 'Préparation à lancer', urgence: 'haute', en_retard: true, depuis: '2026-10-19T08:00:00Z' },
    ] });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.textContent).toContain('2 action(s) en attente');
    expect(el.textContent).toContain('1 en retard');
    expect(el.querySelector('a[href="/entrepot/incidents"]')).not.toBeNull();
    expect(el.querySelector('a[href="/commandes/8"]')).not.toBeNull();
  });

  it('À faire vide : message clair et raccourcis du rôle', () => {
    configurer('acheteur_gros');
    const f = TestBed.createComponent(AFaireComponent);
    f.detectChanges();
    http.expectOne(`${API}/a-faire`).flush({ elements: [] });
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('Tout est à jour');
    expect(f.nativeElement.querySelector('a[href="/acheteur/catalogue"]')).not.toBeNull();
  });

  it('fiche de commande : étapes datées, boutons du serveur, action directe', () => {
    configurer();
    const f = TestBed.createComponent(FicheCommandeComponent);
    f.detectChanges();
    const fiche = {
      role: 'gestionnaire', commande: { id: 8, numero_commande: 'CMD-8', produit: 'riz', quantite: 10, statut: 'confirmee', prix: 1500, prix_unitaire: 150, date_commande: '2026-10-21T08:00:00Z' },
      actions: ['preparer', 'annuler_commande'], actions_livraison: [], prochaine_etape: { libelle: 'Préparation à lancer' }, code_reception: null,
      lot: { code_tracabilite: 'LOT-2026-AAAAAA', entrepot: 'Nord' }, acheteur: { nom: 'Fatou Sow', telephone: '771234567' }, livraison: null,
      etapes: [{ date: '2026-10-21T08:00:00Z', libelle: 'Création', auteur: 'Fatou Sow', role: 'acheteur_gros', sujet: 'commande' }], incidents: [],
    };
    http.expectOne(`${API}/fiches/commande/8`).flush(fiche);
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.textContent).toContain('Préparation à lancer');
    expect(el.textContent).toContain('Fatou Sow');
    expect(el.querySelector('a[href="/entrepot/commandes"]')).not.toBeNull();
    (Array.from(el.querySelectorAll('button')).find(b => b.textContent?.includes('Lancer la préparation')) as HTMLButtonElement).click();
    http.expectOne(r => r.method === 'PUT' && r.url === `${API}/commandes/8/preparer`).flush({ message: 'Préparation lancée.' });
    http.expectOne(`${API}/fiches/commande/8`).flush({ ...fiche, actions: [], commande: { ...fiche.commande, statut: 'en_preparation' } });
    f.detectChanges();
    expect(el.textContent).toContain('Préparation lancée.');
  });

  it('fiche refusée : message d\'accès, sans donnée', () => {
    configurer('acheteur_gros');
    const f = TestBed.createComponent(FicheCommandeComponent);
    f.detectChanges();
    http.expectOne(`${API}/fiches/commande/8`).flush({ message: 'x' }, { status: 403, statusText: 'Forbidden' });
    f.detectChanges();
    expect(f.nativeElement.textContent).toContain('pas accès');
  });

  it('exceptions de l\'administrateur, des plus graves aux mineures', () => {
    configurer('administrateur');
    const f = TestBed.createComponent(ExceptionsComponent);
    f.detectChanges();
    http.expectOne(`${API}/admin/exceptions`).flush({ exceptions: [
      { gravite: 'haute', categorie: 'gps', numero: 'LIV-3', message: 'Livraison partie sans aucune position reçue', depuis: null, lien: { sujet_type: 'livraison', sujet_id: 3 } },
      { gravite: 'moyenne', categorie: 'commande', numero: 'CMD-9', message: 'Commande en attente de confirmation depuis plus de 48 h', depuis: null, lien: { sujet_type: 'commande', sujet_id: 9 } },
    ], par_gravite: { haute: 1, moyenne: 1, basse: 0 } });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.textContent).toContain('1 grave(s)');
    expect(el.querySelectorAll('.exception')[0].textContent).toContain('Grave');
    expect(el.querySelector('a[href="/commandes/9"]')).not.toBeNull();
  });
});
