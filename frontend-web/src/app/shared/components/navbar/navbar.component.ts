import { Component, computed, inject } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive],
  template: `
    <nav class="navbar">
      <div class="navbar-brand">
        <span class="logo">🌾 SenAgriLink</span>
      </div>
      <ul class="navbar-links">
        @if (role() === 'producteur') {
          <li><a routerLink="/producteur/productions" routerLinkActive="active">Mes productions</a></li>
          <li><a routerLink="/producteur/planifier-ventes" routerLinkActive="active">Planifier ventes</a></li>
          <li><a routerLink="/producteur/commandes-disponibles" routerLinkActive="active">Demandes</a></li>
        }
        @if (role() === 'gestionnaire_entrepot') {
          <li><a routerLink="/entrepot/stocks" routerLinkActive="active">Stocks</a></li>
          <li><a routerLink="/entrepot/commandes" routerLinkActive="active">Commandes</a></li>
          <li><a routerLink="/entrepot/alertes" routerLinkActive="active">Alertes</a></li>
          <li><a routerLink="/entrepot/rapport" routerLinkActive="active">Rapport</a></li>
        }
        @if (role() === 'acheteur_gros') {
          <li><a routerLink="/acheteur/catalogue" routerLinkActive="active">Catalogue</a></li>
          <li><a routerLink="/acheteur/mes-commandes" routerLinkActive="active">Mes commandes</a></li>
        }
        @if (role() === 'administrateur') {
          <li><a routerLink="/admin/dashboard" routerLinkActive="active">Tableau de bord</a></li>
          <li><a routerLink="/admin/utilisateurs" routerLinkActive="active">Utilisateurs</a></li>
        }
        <!-- Traçabilité : tous les rôles -->
        <li><a routerLink="/tracabilite" routerLinkActive="active">Traçabilité</a></li>
      </ul>
      <div class="navbar-user">
        <a routerLink="/profil" class="user-name user-profil-link">{{ userName() }}</a>
        <span class="role-badge role-{{ role() }}">{{ roleFr() }}</span>
        <button class="btn-logout" (click)="logout()">Déconnexion</button>
      </div>
    </nav>
  `,
  styles: [`
    .navbar {
      display: flex; align-items: center; gap: 1rem;
      background: #2d6a4f; color: white;
      padding: 0.75rem 2rem; box-shadow: 0 2px 4px rgba(0,0,0,.2);
    }
    .logo { font-size: 1.2rem; font-weight: 700; margin-right: 1rem; }
    .navbar-links { display: flex; gap: 0.5rem; list-style: none; margin: 0; padding: 0; flex: 1; }
    .navbar-links a {
      color: rgba(255,255,255,.85); text-decoration: none;
      padding: 0.4rem 0.8rem; border-radius: 4px; font-size: .9rem;
      transition: background .2s;
    }
    .navbar-links a:hover, .navbar-links a.active { background: rgba(255,255,255,.2); color: white; }
    .navbar-user { display: flex; align-items: center; gap: .75rem; }
    .user-name { font-weight: 600; font-size: .9rem; }
    .user-profil-link {
      color: white; text-decoration: none;
      padding: .25rem .5rem; border-radius: 4px; transition: background .2s;
    }
    .user-profil-link:hover { background: rgba(255,255,255,.2); }
    .role-badge {
      font-size: .75rem; padding: .2rem .6rem; border-radius: 12px;
      background: rgba(255,255,255,.25); white-space: nowrap;
    }
    .btn-logout {
      background: rgba(255,255,255,.15); color: white; border: 1px solid rgba(255,255,255,.4);
      padding: .35rem .8rem; border-radius: 4px; cursor: pointer; font-size: .85rem;
      transition: background .2s;
    }
    .btn-logout:hover { background: rgba(255,255,255,.3); }
  `],
})
export class NavbarComponent {
  private auth = inject(AuthService);

  role     = this.auth.role;
  userName = computed(() => {
    const u = this.auth.utilisateur();
    return u ? `${u.prenom} ${u.nom}` : '';
  });

  private readonly ROLES_FR: Record<string, string> = {
    producteur:            'Producteur',
    gestionnaire_entrepot: 'Gestionnaire',
    acheteur_gros:         'Acheteur',
    transporteur:          'Transporteur',
    administrateur:        'Admin',
  };
  roleFr = computed(() => this.ROLES_FR[this.role() ?? ''] ?? '');

  logout(): void { this.auth.logout(); }
}
