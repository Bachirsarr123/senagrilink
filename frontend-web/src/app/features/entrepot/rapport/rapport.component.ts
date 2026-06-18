import { Component, OnInit, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-rapport',
  standalone: true,
  imports: [DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Rapport d'inventaire</h1></div>
      @if (chargement()) { <p class="loading">Chargement...</p> }
      @else if (rapport()) {
        <div class="stats-grid">
          <div class="stat-card stat-card--blue">
            <div class="stat-icon">📦</div>
            <div class="stat-body">
              <span class="stat-label">Lignes de stock</span>
              <span class="stat-value">{{ rapport().total_lignes_stock }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--green">
            <div class="stat-icon">✅</div>
            <div class="stat-body">
              <span class="stat-label">Disponibles</span>
              <span class="stat-value">{{ rapport().lignes_disponibles }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--orange">
            <div class="stat-icon">⚠️</div>
            <div class="stat-body">
              <span class="stat-label">En alerte</span>
              <span class="stat-value">{{ rapport().lignes_en_alerte }}</span>
            </div>
          </div>
          <div class="stat-card stat-card--purple">
            <div class="stat-icon">⚖️</div>
            <div class="stat-body">
              <span class="stat-label">Quantité totale</span>
              <span class="stat-value">{{ rapport().quantite_totale | number }}</span>
              <span class="stat-sub">kg</span>
            </div>
          </div>
        </div>

        <div class="card">
          <h2>Détail — {{ rapport().entrepot?.nom }}</h2>
          <p style="color:#6b7280;font-size:.85rem">📍 {{ rapport().entrepot?.localisation }}</p>
          <table class="table">
            <thead>
              <tr><th>Produit</th><th>Quantité</th><th>Seuil alerte</th><th>Date entrée</th><th>Statut</th></tr>
            </thead>
            <tbody>
              @for (s of rapport().stocks; track s.id) {
                <tr [class.row-alert]="s.seuil_alerte && s.quantite <= s.seuil_alerte">
                  <td><strong>{{ s.produit }}</strong></td>
                  <td>{{ s.quantite | number }} kg</td>
                  <td>{{ s.seuil_alerte ? (s.seuil_alerte | number) + ' kg' : '—' }}</td>
                  <td>{{ s.date_entree }}</td>
                  <td><span class="badge badge-{{ s.statut }}">{{ s.statut }}</span></td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class RapportComponent implements OnInit {
  rapport    = signal<any>(null);
  chargement = signal(true);
  constructor(private api: ApiService) {}
  ngOnInit(): void {
    this.api.getRapport().subscribe({
      next: res => { this.rapport.set(res.rapport); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }
}
