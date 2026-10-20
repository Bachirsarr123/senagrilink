import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { environment } from '../../environments/environment';
import { CleSoumission } from '../shared/idempotence/cle-soumission';
import { CatalogueComponent } from './acheteur/catalogue/catalogue.component';
import { CommandesEntrepotComponent } from './entrepot/commandes-entrepot/commandes-entrepot.component';
import { AlertesComponent } from './entrepot/alertes/alertes.component';
import { ProfilComponent } from './shared/profil/profil.component';
import { ProductionsComponent } from './producteur/productions/productions.component';

/** Non-régression des corrections du lot 0 côté site web. */
const API = environment.apiUrl;

describe('Lot 0 — site web', () => {
  let http: HttpTestingController;

  function configurer(): void {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    http = TestBed.inject(HttpTestingController);
  }

  afterEach(() => { http?.verify(); vi.restoreAllMocks(); localStorage.clear(); });

  it('CleSoumission : format accepté par le serveur, renouvelée sur demande', () => {
    const cle = new CleSoumission();
    const premiere = cle.valeur;
    expect(premiere).toMatch(/^[A-Za-z0-9_-]{8,100}$/);
    expect(cle.valeur).toBe(premiere);
    cle.renouveler();
    expect(cle.valeur).not.toBe(premiere);
  });

  describe('catalogue : commande sans doublon (P-25)', () => {
    const PRODUIT = { stock_id: 5, produit: 'riz', quantite: 40, entrepot: { nom_entrepot: 'Nord' } };

    function creer() {
      configurer();
      const fixture = TestBed.createComponent(CatalogueComponent);
      fixture.detectChanges();
      http.expectOne(`${API}/catalogue`).flush({ catalogue: [PRODUIT] });
      fixture.detectChanges();
      return fixture.componentInstance;
    }

    it('nouvel essai après une erreur : même clé ; commande suivante : nouvelle clé', () => {
      const comp = creer();
      comp.ouvrirCommande(PRODUIT);
      comp.formCommande.setValue({ quantite: 5, prix: null });

      comp.passerCommande(PRODUIT);
      const essai1 = http.expectOne(`${API}/commandes`);
      const cle = essai1.request.headers.get('Idempotency-Key');
      expect(cle).toMatch(/^[A-Za-z0-9_-]{8,100}$/);
      essai1.flush({ message: 'Erreur' }, { status: 502, statusText: 'Bad Gateway' });

      comp.passerCommande(PRODUIT);
      const essai2 = http.expectOne(`${API}/commandes`);
      expect(essai2.request.headers.get('Idempotency-Key')).toBe(cle);
      essai2.flush({ commande: { numero_commande: 'CMD-1' } }, { status: 201, statusText: 'Created' });

      comp.ouvrirCommande(PRODUIT);
      comp.formCommande.setValue({ quantite: 5, prix: null });
      comp.passerCommande(PRODUIT);
      const suivante = http.expectOne(`${API}/commandes`);
      expect(suivante.request.headers.get('Idempotency-Key')).not.toBe(cle);
      suivante.flush({ commande: { numero_commande: 'CMD-2' } }, { status: 201, statusText: 'Created' });
    });
  });

  describe('commandes de l\'entrepôt : confirmation acquise puis livraison en échec (P-16)', () => {
    it('le nouvel essai crée seulement la livraison, avec la même clé', () => {
      configurer();
      const fixture = TestBed.createComponent(CommandesEntrepotComponent);
      fixture.detectChanges();
      const commande = { id: 3, numero_commande: 'CMD-3', produit: 'riz', quantite: 10, statut: 'en_attente', acheteur: {} };
      http.expectOne(`${API}/commandes`).flush({ commandes: [commande] });
      http.expectOne(`${API}/transporteurs`).flush({ transporteurs: [{ id: 1, utilisateur: { prenom: 'T', nom: 'U' } }] });
      const comp = fixture.componentInstance;

      comp.ouvrirLivraison(commande);
      comp.formLivraison.setValue({ transporteur_id: 1, origine: 'Entrepôt', destination: 'Dakar', date_depart: '2026-10-21T08:00', date_livraison: '' });
      comp.confirmerAvecLivraison();
      http.expectOne(`${API}/commandes/3/confirmer`).flush({ commande: { ...commande, statut: 'confirmee' } });
      const liv1 = http.expectOne(`${API}/livraisons`);
      const cle = liv1.request.headers.get('Idempotency-Key');
      liv1.flush({ message: 'Erreur serveur' }, { status: 500, statusText: 'Server Error' });
      http.expectOne(`${API}/commandes`).flush({ commandes: [{ ...commande, statut: 'confirmee' }] });
      expect(comp.erreurModal()).toContain('La commande est confirmée');

      comp.confirmerAvecLivraison();
      http.expectNone(`${API}/commandes/3/confirmer`);
      const liv2 = http.expectOne(`${API}/livraisons`);
      expect(liv2.request.headers.get('Idempotency-Key')).toBe(cle);
      liv2.flush({ livraison: { id: 1 } }, { status: 201, statusText: 'Created' });
      http.expectOne(`${API}/commandes`).flush({ commandes: [] });
    });
  });

  describe('alertes de stock (P-21)', () => {
    it('erreur de chargement : message et Réessayer, jamais « tout va bien »', () => {
      configurer();
      const fixture = TestBed.createComponent(AlertesComponent);
      fixture.detectChanges();
      http.expectOne(`${API}/stocks/alertes`).flush({ message: 'Profil entrepôt introuvable.' }, { status: 404, statusText: 'Not Found' });
      fixture.detectChanges();
      const texte = (fixture.nativeElement as HTMLElement).textContent ?? '';
      expect(texte).toContain('Profil entrepôt introuvable.');
      expect(texte).not.toContain('Tous les stocks sont au-dessus');

      (Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button')).find(b => b.textContent?.includes('Réessayer')) as HTMLButtonElement).click();
      http.expectOne(`${API}/stocks/alertes`).flush({ alertes: [] });
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).textContent).toContain('Tous les stocks sont au-dessus');
    });
  });

  describe('profil (P-17)', () => {
    function creer() {
      localStorage.setItem('agri_token', 'jeton');
      localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Diallo', prenom: 'Awa', email: 'a@test.sn', adresse: 'Thiès, quartier Nord', role: 'acheteur_gros' }));
      configurer();
      const fixture = TestBed.createComponent(ProfilComponent);
      fixture.detectChanges();
      return fixture.componentInstance;
    }

    it('l\'adresse enregistrée est conservée à l\'enregistrement du profil', () => {
      const comp = creer();
      comp.sauvegarder();
      const req = http.expectOne(`${API}/profile`);
      expect(req.request.body.adresse).toBe('Thiès, quartier Nord');
      expect(req.request.body.mot_de_passe).toBeUndefined();
      req.flush({ utilisateur: { id: 1, nom: 'Diallo', prenom: 'Awa', email: 'a@test.sn', role: 'acheteur_gros' } });
    });

    it('changement de mot de passe : mot de passe actuel exigé puis transmis', () => {
      const comp = creer();
      comp.form.patchValue({ mot_de_passe: 'nouveau-mdp-2', mot_de_passe_confirmation: 'nouveau-mdp-2' });
      comp.sauvegarder();
      http.expectNone(`${API}/profile`);
      expect(comp.erreurMdp()).toContain('mot de passe actuel');

      comp.form.patchValue({ mot_de_passe_actuel: 'ancien-mdp-1' });
      comp.sauvegarder();
      const req = http.expectOne(`${API}/profile`);
      expect(req.request.body).toMatchObject({ mot_de_passe_actuel: 'ancien-mdp-1', mot_de_passe: 'nouveau-mdp-2', mot_de_passe_confirmation: 'nouveau-mdp-2' });
      req.flush({ utilisateur: { id: 1, nom: 'Diallo', prenom: 'Awa', email: 'a@test.sn', role: 'acheteur_gros' } });
    });
  });
  describe('récolte : une clé par ouverture du formulaire', () => {
    it('même clé pour le nouvel essai, nouvelle clé à la réouverture', () => {
      configurer();
      const fixture = TestBed.createComponent(ProductionsComponent);
      fixture.detectChanges();
      http.expectOne(`${API}/productions`).flush({ productions: [] });
      const comp = fixture.componentInstance;
      const remplir = () => comp.formCreation.setValue({ type_culture: 'mil', superficie: null, date_recolte: '2026-06-01', quantite_estimee: 10, quantite_reelle: null });
      const envoyer = () => { comp.soumettre(); return http.expectOne(`${API}/productions`); };

      comp.toggleFormCreation(); remplir();
      const e1 = envoyer(); const cle = e1.request.headers.get('Idempotency-Key');
      e1.flush({ message: 'Erreur' }, { status: 503, statusText: 'Unavailable' });
      const e2 = envoyer();
      expect(e2.request.headers.get('Idempotency-Key')).toBe(cle);
      e2.flush({ message: 'Erreur' }, { status: 503, statusText: 'Unavailable' });

      vi.spyOn(window, 'confirm').mockReturnValue(true);
      comp.toggleFormCreation(); // fermeture
      comp.toggleFormCreation(); // réouverture : nouvelle saisie
      remplir();
      const e3 = envoyer();
      expect(e3.request.headers.get('Idempotency-Key')).not.toBe(cle);
      e3.flush({ production: { id: 1, code_tracabilite: 'LOT-1' } }, { status: 201, statusText: 'Created' });
    });
  });
});
