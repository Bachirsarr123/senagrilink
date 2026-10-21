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
    const PRODUIT = { stock_id: 5, produit: 'riz', quantite: 40, quantite_disponible: 40, prix_unitaire: 150, entrepot: { nom_entrepot: 'Nord' } };

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
      comp.formCommande.setValue({ quantite: 5 });
      expect(comp.montant(PRODUIT)).toBe(750);

      comp.passerCommande(PRODUIT);
      const essai1 = http.expectOne(`${API}/commandes`);
      const cle = essai1.request.headers.get('Idempotency-Key');
      expect(cle).toMatch(/^[A-Za-z0-9_-]{8,100}$/);
      essai1.flush({ message: 'Erreur' }, { status: 502, statusText: 'Bad Gateway' });

      comp.passerCommande(PRODUIT);
      const essai2 = http.expectOne(`${API}/commandes`);
      expect(essai2.request.headers.get('Idempotency-Key')).toBe(cle);
      essai2.flush({ commande: { numero_commande: 'CMD-1' } }, { status: 201, statusText: 'Created' });
      // Après la commande, le catalogue est rechargé (disponible à jour)
      http.expectOne(`${API}/catalogue`).flush({ catalogue: [PRODUIT] });

      comp.ouvrirCommande(PRODUIT);
      comp.formCommande.setValue({ quantite: 5 });
      comp.passerCommande(PRODUIT);
      const suivante = http.expectOne(`${API}/commandes`);
      expect(suivante.request.headers.get('Idempotency-Key')).not.toBe(cle);
      suivante.flush({ commande: { numero_commande: 'CMD-2' } }, { status: 201, statusText: 'Created' });
      http.expectOne(`${API}/catalogue`).flush({ catalogue: [PRODUIT] });
    });
  });

  describe('commandes de l\'entrepôt : organisation de la livraison en échec (P-16)', () => {
    it('le nouvel essai réutilise la même clé : jamais deux livraisons', () => {
      configurer();
      const fixture = TestBed.createComponent(CommandesEntrepotComponent);
      fixture.detectChanges();
      const commande = { id: 3, numero_commande: 'CMD-3', produit: 'riz', quantite: 10, statut: 'prete', acheteur: {}, actions: ['organiser_livraison', 'annuler_commande'] };
      http.expectOne(`${API}/commandes`).flush({ commandes: [commande] });
      const comp = fixture.componentInstance;

      comp.agir(commande, 'organiser_livraison');
      http.expectOne(`${API}/transporteurs`).flush({ transporteurs: [{ id: 1, utilisateur: { prenom: 'T', nom: 'U' }, missions_actives: 0 }] });
      comp.form.patchValue({ transporteur_id: 1 });
      comp.valider();
      const liv1 = http.expectOne(`${API}/livraisons`);
      const cle = liv1.request.headers.get('Idempotency-Key');
      expect(liv1.request.body).toEqual({ commande_id: 3, transporteur_id: 1 });
      liv1.error(new ProgressEvent('error')); // réponse perdue
      expect(comp.erreurModal()).toContain('ne sera pas créée deux fois');

      comp.valider();
      const liv2 = http.expectOne(`${API}/livraisons`);
      expect(liv2.request.headers.get('Idempotency-Key')).toBe(cle);
      liv2.flush({ livraison: { id: 1 } }, { status: 201, statusText: 'Created' });
      http.expectOne(`${API}/commandes`).flush({ commandes: [] });
      expect(comp.message()).toContain('doit l\'accepter');
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
      const remplir = () => comp.formCreation.setValue({ type_culture: 'mil', superficie: null, date_recolte: '2026-06-01', quantite_estimee: 10, quantite_reelle: null, prix_unitaire: 300 });
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
