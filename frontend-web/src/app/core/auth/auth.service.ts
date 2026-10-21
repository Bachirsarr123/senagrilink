import { Injectable, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { tap } from 'rxjs/operators';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthResponse, LoginPayload, RegisterPayload, Utilisateur } from './auth.model';

export type RaisonFinSession = 'session_expiree' | 'compte_bloque';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly TOKEN_KEY = 'agri_token';
  private readonly USER_KEY  = 'agri_user';

  private _utilisateur = signal<Utilisateur | null>(this.loadUser());
  readonly utilisateur  = this._utilisateur.asReadonly();
  readonly isLoggedIn   = computed(() => !!this._utilisateur());
  readonly role         = computed(() => this._utilisateur()?.role ?? null);

  constructor(private http: HttpClient, private router: Router) {}

  login(payload: LoginPayload): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${environment.apiUrl}/login`, payload).pipe(
      tap(res => this.persistSession(res))
    );
  }

  register(payload: RegisterPayload): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${environment.apiUrl}/register`, payload).pipe(
      // Compte en attente de validation : pas de jeton, pas de session.
      tap(res => { if (!res.en_attente_validation) this.persistSession(res); })
    );
  }

  logout(): void {
    this.http.post(`${environment.apiUrl}/logout`, {}).subscribe({
      complete: () => this.clearSession(),
      error:    () => this.clearSession(),
    });
  }

  getToken(): string | null {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  /** Page d'accueil de l'espace du rôle connecté. */
  routeAccueil(): string {
    const routes: Record<string, string> = {
      // Lot 5 : chaque rôle arrive sur ce qui l'attend ; l'administrateur sur ses exceptions.
      producteur:            '/a-faire',
      gestionnaire_entrepot: '/a-faire',
      acheteur_gros:         '/a-faire',
      administrateur:        '/admin/dashboard',
      transporteur:          '/a-faire',
    };
    // Repli sur le profil (protégé) et non sur /login, pour éviter toute boucle.
    return routes[this.role() ?? ''] ?? '/profil';
  }

  redirectAfterLogin(): void {
    this.router.navigateByUrl(this.routeAccueil());
  }

  /**
   * Session refusée par l'API (jeton expiré, révoqué, ou compte bloqué) : on
   * efface la session locale sans rappeler l'API (qui répondrait encore 401)
   * et on renvoie vers la connexion une seule fois, sans boucle de redirection.
   */
  invaliderSession(raison: RaisonFinSession): void {
    const avaitUneSession = !!this.getToken() || !!this._utilisateur();
    localStorage.removeItem(this.TOKEN_KEY);
    localStorage.removeItem(this.USER_KEY);
    this._utilisateur.set(null);

    if (avaitUneSession && !this.router.url.startsWith('/login')) {
      this.router.navigate(['/login'], { queryParams: { raison } });
    }
  }

  /** Met à jour l'utilisateur en mémoire et en local storage sans re-authentifier. */
  mettreAJourUtilisateur(utilisateur: Utilisateur): void {
    localStorage.setItem(this.USER_KEY, JSON.stringify(utilisateur));
    this._utilisateur.set(utilisateur);
  }

  private persistSession(res: AuthResponse): void {
    localStorage.setItem(this.TOKEN_KEY, res.access_token);
    localStorage.setItem(this.USER_KEY,  JSON.stringify(res.utilisateur));
    this._utilisateur.set(res.utilisateur);
  }

  private clearSession(): void {
    localStorage.removeItem(this.TOKEN_KEY);
    localStorage.removeItem(this.USER_KEY);
    this._utilisateur.set(null);
    this.router.navigate(['/login']);
  }

  /**
   * Restaure la session au chargement (rechargement, réouverture du navigateur).
   * Elle n'est valide que si le jeton est présent : un utilisateur sans jeton
   * passerait la garde sans pouvoir appeler l'API.
   */
  private loadUser(): Utilisateur | null {
    try {
      if (!localStorage.getItem(this.TOKEN_KEY)) return null;
      const raw = localStorage.getItem(this.USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
}
