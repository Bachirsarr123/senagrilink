import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { environment } from '../../../environments/environment';
import { authInterceptor } from './auth.interceptor';

const API = environment.apiUrl;

describe('authInterceptor (R4)', () => {
  let http: HttpClient;
  let backend: HttpTestingController;
  let router: Router;

  function connecter(): void {
    localStorage.setItem('agri_token', 'jeton-fictif');
    localStorage.setItem('agri_user', JSON.stringify({ id: 1, nom: 'Test', prenom: 'A', role: 'acheteur_gros' }));
  }

  function appeler(url: string): void {
    http.get(url).subscribe({ next: () => {}, error: () => {} });
  }

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });
    http    = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
    router  = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);
  });

  afterEach(() => backend.verify());

  it('joint le jeton aux appels authentifiés de l\'API', () => {
    connecter();
    appeler(`${API}/catalogue`);
    expect(backend.expectOne(`${API}/catalogue`).request.headers.get('Authorization')).toBe('Bearer jeton-fictif');
  });

  it('n\'envoie jamais le jeton aux routes publiques ni aux hôtes tiers', () => {
    connecter();
    http.post(`${API}/login`, {}).subscribe();
    http.post(`${API}/register`, {}).subscribe();
    appeler('https://tile.openstreetmap.org/1/1/1.png');

    expect(backend.expectOne(`${API}/login`).request.headers.has('Authorization')).toBe(false);
    expect(backend.expectOne(`${API}/register`).request.headers.has('Authorization')).toBe(false);
    expect(backend.expectOne('https://tile.openstreetmap.org/1/1/1.png').request.headers.has('Authorization')).toBe(false);
  });

  it('401 sur une requête authentifiée : session locale supprimée et retour à la connexion', () => {
    connecter();
    appeler(`${API}/me`);
    backend.expectOne(`${API}/me`).flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(localStorage.getItem('agri_token')).toBeNull();
    expect(localStorage.getItem('agri_user')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], { queryParams: { raison: 'session_expiree' } });
  });

  it('plusieurs 401 simultanés ne provoquent qu\'une seule redirection', () => {
    connecter();
    appeler(`${API}/me`);
    appeler(`${API}/notifications`);
    backend.expectOne(`${API}/me`).flush({}, { status: 401, statusText: 'Unauthorized' });
    backend.expectOne(`${API}/notifications`).flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(router.navigate).toHaveBeenCalledTimes(1);
  });

  it('403 de permission ordinaire : la session est conservée', () => {
    connecter();
    appeler(`${API}/stocks`);
    backend.expectOne(`${API}/stocks`).flush({ message: 'Accès refusé. Rôle insuffisant.' }, { status: 403, statusText: 'Forbidden' });

    expect(localStorage.getItem('agri_token')).toBe('jeton-fictif');
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('403 compte bloqué : session supprimée avec un motif distinct', () => {
    connecter();
    appeler(`${API}/me`);
    backend.expectOne(`${API}/me`).flush(
      { message: 'Votre compte a été bloqué.', code: 'compte_bloque' },
      { status: 403, statusText: 'Forbidden' },
    );

    expect(localStorage.getItem('agri_token')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], { queryParams: { raison: 'compte_bloque' } });
  });

  it('erreur réseau, délai dépassé ou erreur serveur : la session est conservée', () => {
    connecter();
    appeler(`${API}/me`);
    appeler(`${API}/catalogue`);
    appeler(`${API}/notifications`);
    backend.expectOne(`${API}/me`).error(new ProgressEvent('error'));
    backend.expectOne(`${API}/catalogue`).flush({}, { status: 503, statusText: 'Service Unavailable' });
    backend.expectOne(`${API}/notifications`).flush({}, { status: 504, statusText: 'Gateway Timeout' });

    expect(localStorage.getItem('agri_token')).toBe('jeton-fictif');
    expect(localStorage.getItem('agri_user')).not.toBeNull();
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('401 tardif d\'un ancien jeton : la session ouverte depuis est conservée', () => {
    connecter();
    appeler(`${API}/me`);
    localStorage.setItem('agri_token', 'nouveau-jeton');
    backend.expectOne(`${API}/me`).flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(localStorage.getItem('agri_token')).toBe('nouveau-jeton');
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('401 sur la connexion (mauvais mot de passe) : pas d\'invalidation ni de redirection', () => {
    http.post(`${API}/login`, {}).subscribe({ error: () => {} });
    backend.expectOne(`${API}/login`).flush({ message: 'Identifiants incorrects.' }, { status: 401, statusText: 'Unauthorized' });

    expect(router.navigate).not.toHaveBeenCalled();
  });
});
