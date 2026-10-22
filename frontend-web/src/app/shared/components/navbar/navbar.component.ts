import { Component, ElementRef, HostListener, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { DatePipe } from '@angular/common';
import { AuthService } from '../../../core/auth/auth.service';
import { ApiService } from '../../../core/services/api.service';
import { EchoService } from '../../../core/services/echo.service';

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, DatePipe],
  template: `
    <nav class="navbar">
      <div class="navbar-brand">
        <span class="logo">🌾 SenAgriLink</span>
        <button class="hamburger" type="button" (click)="basculerMenuMobile()"
                [attr.aria-label]="menuMobileOuvert() ? 'Fermer le menu' : 'Ouvrir le menu'"
                [attr.aria-expanded]="menuMobileOuvert()" aria-controls="navbar-menu">
          {{ menuMobileOuvert() ? '✕' : '☰' }}
        </button>
      </div>
      <div class="navbar-collapsible" id="navbar-menu" [class.open]="menuMobileOuvert()">
        <ul class="navbar-links" (click)="fermerMenuMobile()">
          @if (role() !== 'administrateur') {
            <li><a routerLink="/a-faire" routerLinkActive="active">À faire</a></li>
          }
          @if (role() === 'producteur') {
            <li><a routerLink="/producteur/productions" routerLinkActive="active">Mes productions</a></li>
            <li><a routerLink="/producteur/reservations" routerLinkActive="active">Déposer ma récolte</a></li>
            <li><a routerLink="/producteur/planifier-ventes" routerLinkActive="active">Planifier ventes</a></li>
            <li><a routerLink="/producteur/commandes-disponibles" routerLinkActive="active">Demandes</a></li>
          }
          @if (role() === 'gestionnaire_entrepot') {
            <li><a routerLink="/entrepot/journee" routerLinkActive="active">Ma journée</a></li>
            <li><a routerLink="/entrepot/agriculteurs" routerLinkActive="active">Agriculteurs</a></li>
            <li><a routerLink="/entrepot/stocks" routerLinkActive="active">Stocks</a></li>
            <li><a routerLink="/entrepot/reservations" routerLinkActive="active">Dépôts</a></li>
            <li><a routerLink="/entrepot/commandes" routerLinkActive="active">Commandes</a></li>
            <li><a routerLink="/entrepot/incidents" routerLinkActive="active">Incidents</a></li>
            <li><a routerLink="/entrepot/alertes" routerLinkActive="active">Alertes</a></li>
            <li><a routerLink="/entrepot/rapport" routerLinkActive="active">Rapport</a></li>
          }
          @if (role() === 'acheteur_gros') {
            <li><a routerLink="/acheteur/catalogue" routerLinkActive="active">Catalogue</a></li>
            <li><a routerLink="/acheteur/mes-commandes" routerLinkActive="active">Mes commandes</a></li>
          }
          @if (role() === 'transporteur') {
            <li><a routerLink="/transporteur/missions" routerLinkActive="active">Mes missions</a></li>
          }
          @if (role() === 'administrateur') {
            <li><a routerLink="/admin/dashboard" routerLinkActive="active">Tableau de bord</a></li>
            <li><a routerLink="/admin/utilisateurs" routerLinkActive="active">Utilisateurs</a></li>
            <li><a routerLink="/admin/journal" routerLinkActive="active">Journal</a></li>
          }
          <!-- Traçabilité : tous les rôles -->
          <li><a routerLink="/tracabilite" routerLinkActive="active">Traçabilité</a></li>
        </ul>
        <div class="navbar-user">
          <div class="notif-wrapper">
            <button class="btn-notif" type="button" (click)="basculerMenu()" aria-label="Notifications"
                    [attr.aria-expanded]="menuOuvert()">
              🔔
              @if (nonLues() > 0) {
                <span class="notif-badge">{{ nonLues() }}</span>
              }
            </button>
            @if (menuOuvert()) {
              <div class="notif-panel">
                <div class="notif-panel-header">Notifications
                  @if (nonLues() > 0) { <button type="button" class="notif-tout-lire" (click)="toutMarquerLu(); $event.stopPropagation()">Tout marquer comme lu</button> }
                </div>
                @if (notifications().length === 0) {
                  <p class="notif-empty">Aucune notification.</p>
                } @else {
                  @for (n of notifications(); track n.id) {
                    <button class="notif-item" [class.non-lue]="!n.read_at" (click)="marquerLue(n)">
                      <span>{{ n.data.message }}</span>
                      <small>{{ n.created_at | date:'dd/MM HH:mm' }}</small>
                    </button>
                  }
                }
              </div>
            }
          </div>
          <a routerLink="/profil" class="user-name user-profil-link" [attr.title]="userName()" (click)="fermerMenuMobile()">{{ userName() }}</a>
          <span class="role-badge role-{{ role() }}">{{ roleFr() }}</span>
          <button class="btn-logout" type="button" (click)="logout()">Déconnexion</button>
        </div>
      </div>
    </nav>
  `,
  styles: [`
    .navbar {
      display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
      background: #2d6a4f; color: white;
      padding: .75rem max(2rem, env(safe-area-inset-right)) .75rem max(2rem, env(safe-area-inset-left));
      padding-top: max(.75rem, env(safe-area-inset-top));
      box-shadow: 0 2px 4px rgba(0,0,0,.2);
    }
    .navbar-brand { display: flex; align-items: center; justify-content: space-between; }
    .navbar-collapsible { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 0; }
    .logo { font-size: 1.2rem; font-weight: 700; margin-right: .5rem; white-space: nowrap; }
    .hamburger {
      display: none; background: none; border: none; color: white; border-radius: 6px;
      font-size: 1.4rem; cursor: pointer; line-height: 1;
      min-width: 44px; min-height: 44px; padding: .25rem .5rem;
    }
    .hamburger:hover { background: rgba(255,255,255,.15); }
    .navbar-links { display: flex; gap: .25rem; list-style: none; margin: 0; padding: 0; flex: 1 0 auto; }
    .navbar-links a {
      color: rgba(255,255,255,.85); text-decoration: none; white-space: nowrap;
      padding: .4rem .55rem; border-radius: 4px; font-size: .9rem;
      transition: background .2s;
    }
    .navbar-links a:hover, .navbar-links a.active { background: rgba(255,255,255,.2); color: white; }
    .navbar-user { display: flex; align-items: center; gap: .75rem; min-width: 0; }
    /* Nom complet en infobulle : un nom très long ne repousse pas la barre sur deux lignes */
    .user-name {
      font-weight: 600; font-size: .9rem;
      max-width: 11rem; min-width: 3rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
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

    .notif-wrapper { position: relative; }
    .btn-notif {
      position: relative; background: none; border: none; color: white;
      font-size: 1.2rem; cursor: pointer; padding: .25rem .4rem; line-height: 1;
      min-width: 40px; min-height: 40px; border-radius: 6px;
    }
    .btn-notif:hover { background: rgba(255,255,255,.15); }
    .notif-badge {
      position: absolute; top: -4px; right: -4px;
      background: #dc2626; color: white; font-size: .65rem; font-weight: 700;
      border-radius: 999px; padding: .05rem .35rem; min-width: 1.1rem; text-align: center;
    }
    .notif-panel {
      position: absolute; top: calc(100% + .5rem); right: 0; z-index: 1000;
      background: white; color: #1f2937; width: min(320px, calc(100vw - 2rem));
      max-height: min(400px, 70vh); overflow-y: auto; overscroll-behavior: contain;
      border-radius: 8px; box-shadow: 0 12px 32px rgba(0,0,0,.25);
    }
    .notif-panel-header { padding: .75rem 1rem; font-weight: 700; border-bottom: 1px solid #e5e7eb; }
    .notif-empty { padding: 1rem; color: #6b7280; font-size: .85rem; margin: 0; }
    .notif-item {
      display: flex; flex-direction: column; gap: .15rem; width: 100%; text-align: left;
      background: none; border: none; border-bottom: 1px solid #f3f4f6; cursor: pointer;
      padding: .6rem 1rem; font-size: .85rem; color: #1f2937;
    }
    .notif-item:hover { background: #f9fafb; }
    .notif-item.non-lue { background: #f0fdf4; font-weight: 600; }
    .notif-tout-lire { float:right; background:none; border:none; color:#2d6a4f; font-size:.75rem; cursor:pointer; text-decoration:underline; }
    .notif-item small { color: #9ca3af; font-weight: 400; }

    /* Menu hamburger sous 1200 px : au-delà, liens et infos utilisateur tiennent
       sur une ligne pour tous les rôles (jusqu'à 5 liens + nom complet). */
    @media (max-width: 1200px) {
      .navbar {
        padding: max(.5rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right))
                 .5rem max(1rem, env(safe-area-inset-left));
      }
      .navbar-brand { width: 100%; }
      .hamburger { display: block; }
      .navbar-collapsible {
        display: none;
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        margin-top: .5rem;
      }
      .navbar-collapsible.open { display: flex; }
      .navbar-links { flex-direction: column; gap: 0; width: 100%; }
      .navbar-links a { display: block; padding: .75rem .5rem; font-size: 1rem; }
      .navbar-user {
        flex-direction: column; align-items: stretch; width: 100%;
        gap: .5rem; padding-top: .5rem; border-top: 1px solid rgba(255,255,255,.15);
      }
      /* Dans le menu replié, le panneau s'ouvre dans le flux, sur toute la largeur
         (ancré à droite d'un bouton aligné à gauche, il sortait de l'écran). */
      .notif-wrapper { align-self: stretch; }
      .notif-panel { position: static; width: 100%; margin-top: .5rem; max-height: 50vh; }
      .user-name { max-width: none; white-space: normal; }
      .user-profil-link, .role-badge, .btn-logout { width: 100%; box-sizing: border-box; }
      .user-profil-link { padding: .65rem .5rem; }
      .role-badge { text-align: center; }
      .btn-logout { min-height: 44px; font-size: .95rem; }
    }
  `],
})
export class NavbarComponent implements OnInit, OnDestroy {
  private auth  = inject(AuthService);
  private api   = inject(ApiService);
  private echo  = inject(EchoService);
  private hote  = inject<ElementRef<HTMLElement>>(ElementRef);

  role     = this.auth.role;
  userName = computed(() => {
    const u = this.auth.utilisateur();
    return u ? `${u.prenom} ${u.nom}` : '';
  });

  notifications    = signal<any[]>([]);
  nonLues          = computed(() => this.notifications().filter(n => !n.read_at).length);
  menuOuvert       = signal(false);
  menuMobileOuvert = signal(false);

  private readonly ROLES_FR: Record<string, string> = {
    producteur:            'Producteur',
    gestionnaire_entrepot: 'Gestionnaire',
    acheteur_gros:         'Acheteur',
    transporteur:          'Transporteur',
    administrateur:        'Admin',
  };
  roleFr = computed(() => this.ROLES_FR[this.role() ?? ''] ?? '');

  ngOnInit(): void {
    this.api.getNotifications().subscribe({
      next: res => this.notifications.set(res.notifications ?? []),
      error: () => {},
    });

    const utilisateurId = this.auth.utilisateur()?.id;
    if (utilisateurId) {
      this.echo.ecouterNotifications(utilisateurId, notification => {
        this.notifications.update(list => [
          { id: notification.id ?? crypto.randomUUID(), data: notification, read_at: null, created_at: new Date().toISOString() },
          ...list,
        ]);
      });
    }
  }

  ngOnDestroy(): void {
    this.echo.deconnecter();
  }

  /** Échap ferme les menus ouverts (clavier). */
  @HostListener('document:keydown.escape')
  fermerMenus(): void {
    this.menuOuvert.set(false);
    this.menuMobileOuvert.set(false);
  }

  /** Un clic hors de la barre ferme le panneau de notifications. */
  @HostListener('document:click', ['$event'])
  clicExterieur(event: MouseEvent): void {
    if (this.menuOuvert() && !this.hote.nativeElement.contains(event.target as Node)) {
      this.menuOuvert.set(false);
    }
  }

  basculerMenu(): void {
    this.menuOuvert.update(v => !v);
  }

  basculerMenuMobile(): void {
    this.menuMobileOuvert.update(v => !v);
  }

  fermerMenuMobile(): void {
    this.menuMobileOuvert.set(false);
  }

  toutMarquerLu(): void {
    this.api.toutMarquerLu().subscribe({
      next: () => this.notifications.update(list => list.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))),
      error: () => {},
    });
  }

  marquerLue(notification: any): void {
    if (notification.read_at) return;

    this.api.marquerNotificationLue(notification.id).subscribe({
      next: () => {
        this.notifications.update(list =>
          list.map(n => n.id === notification.id ? { ...n, read_at: new Date().toISOString() } : n)
        );
      },
      error: () => {},
    });
  }

  logout(): void { this.auth.logout(); }
}
