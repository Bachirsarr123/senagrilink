import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthService } from '../auth/auth.service';

/** Routes de l'API appelées sans jeton (le jeton n'y est jamais envoyé). */
const ROUTES_PUBLIQUES = ['/login', '/register'];

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);

  // Le jeton n'est joint qu'aux appels de l'API SenAgriLink, hors routes publiques :
  // jamais aux hôtes tiers (tuiles de carte, etc.).
  const versApi  = req.url.startsWith(environment.apiUrl);
  const publique = ROUTES_PUBLIQUES.some(route => req.url === `${environment.apiUrl}${route}`);
  const token    = versApi && !publique ? auth.getToken() : null;

  if (token) {
    req = req.clone({ setHeaders: { Authorization: `Bearer ${token}` } });
  }

  return next(req).pipe(
    catchError((erreur: unknown) => {
      // Seules les requêtes authentifiées peuvent invalider la session : un 401 sur
      // /login (mauvais mot de passe) reste une erreur affichée par le formulaire.
      if (token && erreur instanceof HttpErrorResponse) {
        if (erreur.status === 401) {
          auth.invaliderSession('session_expiree');
        } else if (erreur.status === 403 && erreur.error?.code === 'compte_bloque') {
          auth.invaliderSession('compte_bloque');
        }
        // Tout autre 403 = permission insuffisante : la session est conservée.
      }
      return throwError(() => erreur);
    }),
  );
};
