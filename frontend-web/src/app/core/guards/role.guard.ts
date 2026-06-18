import { inject } from '@angular/core';
import { CanActivateFn, Router, ActivatedRouteSnapshot } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { Role } from '../auth/auth.model';

export const roleGuard: CanActivateFn = (route: ActivatedRouteSnapshot) => {
  const auth           = inject(AuthService);
  const router         = inject(Router);
  const allowedRoles   = route.data['roles'] as Role[];
  const currentRole    = auth.role();

  if (!currentRole) {
    router.navigate(['/login']);
    return false;
  }

  if (currentRole === 'administrateur' || allowedRoles.includes(currentRole)) {
    return true;
  }

  router.navigate(['/unauthorized']);
  return false;
};
