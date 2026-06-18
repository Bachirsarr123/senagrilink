import { Injectable, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { tap } from 'rxjs/operators';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthResponse, LoginPayload, RegisterPayload, Utilisateur } from './auth.model';

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
      tap(res => this.persistSession(res))
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

  redirectAfterLogin(): void {
    const role = this.role();
    const routes: Record<string, string> = {
      producteur:            '/producteur/productions',
      gestionnaire_entrepot: '/entrepot/stocks',
      acheteur_gros:         '/acheteur/catalogue',
      administrateur:        '/admin/dashboard',
    };
    this.router.navigate([routes[role ?? ''] ?? '/login']);
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

  private loadUser(): Utilisateur | null {
    try {
      const raw = localStorage.getItem(this.USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
}
