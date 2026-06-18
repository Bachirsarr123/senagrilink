import { Component, OnInit, signal } from '@angular/core';
import { DecimalPipe, KeyValuePipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [DecimalPipe, KeyValuePipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Tableau de bord</h1></div>

      @if (chargement()) { <p class="loading">Chargement des statistiques...</p> }
      @else if (stats()) {
        <div class="stats-grid">
          <div class="stat-card stat-card--green">
            <div class="stat-icon">🌱</div>
            <div class="stat-body">
              <span class="stat-label">Productions</span>
              <span class="stat-value">{{ stats().productions.total | number }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--blue">
            <div class="stat-icon">📦</div>
            <div class="stat-body">
              <span class="stat-label">Lignes de stock</span>
              <span class="stat-value">{{ stats().stocks.total_lignes | number }}</span>
              <span class="stat-sub">{{ stats().stocks.quantite_totale | number }} kg total</span>
            </div>
          </div>
          <div class="stat-card stat-card--orange">
            <div class="stat-icon">🛒</div>
            <div class="stat-body">
              <span class="stat-label">Commandes</span>
              <span class="stat-value">{{ stats().commandes.total | number }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--purple">
            <div class="stat-icon">🚚</div>
            <div class="stat-body">
              <span class="stat-label">Livraisons</span>
              <span class="stat-value">{{ stats().livraisons.total | number }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--red">
            <div class="stat-icon">⚠️</div>
            <div class="stat-body">
              <span class="stat-label">Alertes stock</span>
              <span class="stat-value">{{ stats().stocks.en_alerte | number }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--teal">
            <div class="stat-icon">👥</div>
            <div class="stat-body">
              <span class="stat-label">Utilisateurs actifs</span>
              <span class="stat-value">{{ stats().utilisateurs.actifs | number }}</span>
              <span class="stat-sub">{{ stats().utilisateurs.bloques }} bloqué(s)</span>
            </div>
          </div>
        </div>

        <div class="cards-row">
          <div class="card">
            <h2>Commandes par statut</h2>
            @for (entry of stats().commandes.par_statut | keyvalue; track entry.key) {
              <div class="stat-row">
                <span class="badge badge-{{ entry.key }}">{{ entry.key }}</span>
                <strong>{{ entry.value }}</strong>
              </div>
            }
          </div>
          <div class="card">
            <h2>Livraisons par statut</h2>
            @for (entry of stats().livraisons.par_statut | keyvalue; track entry.key) {
              <div class="stat-row">
                <span class="badge badge-{{ entry.key }}">{{ entry.key }}</span>
                <strong>{{ entry.value }}</strong>
              </div>
            }
          </div>
          <div class="card">
            <h2>Utilisateurs par rôle</h2>
            @for (entry of stats().utilisateurs.par_role | keyvalue; track entry.key) {
              <div class="stat-row">
                <span>{{ entry.key }}</span>
                <strong>{{ entry.value }}</strong>
              </div>
            }
          </div>
        </div>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class DashboardComponent implements OnInit {
  stats      = signal<any>(null);
  chargement = signal(true);

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.api.getDashboard().subscribe({
      next: res => { this.stats.set(res); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }
}
