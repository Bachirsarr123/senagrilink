import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';

/**
 * Pages de connexion et d'inscription : un utilisateur dont la session est
 * encore enregistrée est renvoyé vers son espace. Sans cette garde, ouvrir le
 * site ou le raccourci de l'écran d'accueil sur « / » ou « /login » affichait le
 * formulaire de connexion alors que la session était toujours valide.
 */
export const inviteGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isLoggedIn() ? inject(Router).parseUrl(auth.routeAccueil()) : true;
};
