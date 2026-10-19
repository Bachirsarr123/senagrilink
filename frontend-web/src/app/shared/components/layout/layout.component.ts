import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { NavbarComponent } from '../navbar/navbar.component';

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [RouterOutlet, NavbarComponent],
  template: `
    <app-navbar />
    <main class="main-content">
      <router-outlet />
    </main>
  `,
  styles: [`
    /* Zones de sécurité (encoche, barre d'accueil) prises en compte en paysage */
    .main-content {
      display: block; min-width: 0; max-width: 1200px; margin: 0 auto;
      padding: 1.5rem max(2rem, env(safe-area-inset-right)) max(2rem, env(safe-area-inset-bottom)) max(2rem, env(safe-area-inset-left));
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1rem max(1rem, env(safe-area-inset-right)) max(1.5rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left));
      }
    }
  `],
})
export class LayoutComponent {}
