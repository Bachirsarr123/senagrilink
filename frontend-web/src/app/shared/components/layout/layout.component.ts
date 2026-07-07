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
    .main-content { padding: 1.5rem 2rem; max-width: 1200px; margin: 0 auto; }
    @media (max-width: 640px) {
      .main-content { padding: 1rem; }
    }
  `],
})
export class LayoutComponent {}
