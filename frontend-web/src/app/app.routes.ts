import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { roleGuard } from './core/guards/role.guard';
import { LayoutComponent } from './shared/components/layout/layout.component';

export const routes: Routes = [
  { path: '', redirectTo: 'login', pathMatch: 'full' },

  // Pages publiques
  {
    path: 'login',
    loadComponent: () => import('./features/auth/login/login.component').then(m => m.LoginComponent),
  },
  {
    path: 'register',
    loadComponent: () => import('./features/auth/register/register.component').then(m => m.RegisterComponent),
  },

  // Pages protégées (layout commun avec navbar)
  {
    path: '',
    component: LayoutComponent,
    canActivate: [authGuard],
    children: [

      // Traçabilité — accessible à tous les rôles authentifiés
      {
        path: 'tracabilite',
        loadComponent: () => import('./features/shared/tracabilite/tracabilite.component').then(m => m.TracabiliteComponent),
      },

      // Profil — accessible à tous les rôles authentifiés
      {
        path: 'profil',
        loadComponent: () => import('./features/shared/profil/profil.component').then(m => m.ProfilComponent),
      },

      // Producteur
      {
        path: 'producteur',
        canActivate: [roleGuard],
        data: { roles: ['producteur'] },
        children: [
          { path: '', redirectTo: 'productions', pathMatch: 'full' },
          {
            path: 'productions',
            loadComponent: () => import('./features/producteur/productions/productions.component').then(m => m.ProductionsComponent),
          },
          {
            path: 'commandes-disponibles',
            loadComponent: () => import('./features/producteur/commandes-disponibles/commandes-disponibles.component').then(m => m.CommandesDisponiblesComponent),
          },
          {
            path: 'planifier-ventes',
            loadComponent: () => import('./features/producteur/planifier-ventes/planifier-ventes.component').then(m => m.PlanifierVentesComponent),
          },
        ],
      },

      // Gestionnaire d'entrepôt
      {
        path: 'entrepot',
        canActivate: [roleGuard],
        data: { roles: ['gestionnaire_entrepot'] },
        children: [
          { path: '', redirectTo: 'stocks', pathMatch: 'full' },
          {
            path: 'stocks',
            loadComponent: () => import('./features/entrepot/stocks/stocks.component').then(m => m.StocksComponent),
          },
          {
            path: 'alertes',
            loadComponent: () => import('./features/entrepot/alertes/alertes.component').then(m => m.AlertesComponent),
          },
          {
            path: 'rapport',
            loadComponent: () => import('./features/entrepot/rapport/rapport.component').then(m => m.RapportComponent),
          },
          {
            path: 'commandes',
            loadComponent: () => import('./features/entrepot/commandes-entrepot/commandes-entrepot.component').then(m => m.CommandesEntrepotComponent),
          },
        ],
      },

      // Acheteur en gros
      {
        path: 'acheteur',
        canActivate: [roleGuard],
        data: { roles: ['acheteur_gros'] },
        children: [
          { path: '', redirectTo: 'catalogue', pathMatch: 'full' },
          {
            path: 'catalogue',
            loadComponent: () => import('./features/acheteur/catalogue/catalogue.component').then(m => m.CatalogueComponent),
          },
          {
            path: 'mes-commandes',
            loadComponent: () => import('./features/acheteur/mes-commandes/mes-commandes.component').then(m => m.MesCommandesComponent),
          },
        ],
      },

      // Administrateur
      {
        path: 'admin',
        canActivate: [roleGuard],
        data: { roles: ['administrateur'] },
        children: [
          { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
          {
            path: 'dashboard',
            loadComponent: () => import('./features/admin/dashboard/dashboard.component').then(m => m.DashboardComponent),
          },
          {
            path: 'utilisateurs',
            loadComponent: () => import('./features/admin/utilisateurs/utilisateurs.component').then(m => m.UtilisateursComponent),
          },
        ],
      },
    ],
  },

  // Fallback
  { path: 'unauthorized', loadComponent: () => import('./features/auth/login/login.component').then(m => m.LoginComponent) },
  { path: '**', redirectTo: 'login' },
];
