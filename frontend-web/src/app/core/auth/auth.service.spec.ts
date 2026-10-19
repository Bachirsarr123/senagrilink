import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { environment } from '../../../environments/environment';
import { AuthService } from './auth.service';

const API = environment.apiUrl;
const UTILISATEUR = { id: 1, nom: 'Test', prenom: 'A', role: 'acheteur_gros' };

describe('AuthService — persistance de la session', () => {
  let backend: HttpTestingController;
  let router: Router;

  /** Nouvelle instance du service, comme après un rechargement ou une réouverture du navigateur. */
  function demarrer(): AuthService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    backend = TestBed.inject(HttpTestingController);
    router  = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);
    return TestBed.inject(AuthService);
  }

  beforeEach(() => localStorage.clear());
  afterEach(() => backend.verify());

  it('la connexion est restaurée après rechargement de la page', () => {
    const auth = demarrer();
    auth.login({ email: 'a@test.sn', mot_de_passe: 'password123' }).subscribe();
    backend.expectOne(`${API}/login`).flush({ access_token: 'jeton', utilisateur: UTILISATEUR });

    const recharge = demarrer();
    expect(recharge.isLoggedIn()).toBe(true);
    expect(recharge.role()).toBe('acheteur_gros');
    expect(recharge.getToken()).toBe('jeton');
  });

  it('pas de session restaurée sans jeton', () => {
    localStorage.setItem('agri_user', JSON.stringify(UTILISATEUR));
    expect(demarrer().isLoggedIn()).toBe(false);
  });

  it('la déconnexion révoque le jeton côté serveur puis efface la session locale', () => {
    localStorage.setItem('agri_token', 'jeton');
    localStorage.setItem('agri_user', JSON.stringify(UTILISATEUR));
    const auth = demarrer();

    auth.logout();
    const req = backend.expectOne(`${API}/logout`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Déconnexion réussie.' });

    expect(auth.isLoggedIn()).toBe(false);
    expect(localStorage.getItem('agri_token')).toBeNull();
    expect(localStorage.getItem('agri_user')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login']);
    expect(demarrer().isLoggedIn()).toBe(false);
  });
});
