import { Component, OnInit, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-alertes',
  standalone: true,
  imports: [DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>⚠️ Alertes de stock</h1>
        @if (!chargement()) {
          <span class="subtitle badge" [class.badge-probleme]="alertes().length > 0" [class.badge-disponible]="alertes().length === 0">
            {{ alertes().length }} produit(s) en dessous du seuil
          </span>
        }
      </div>
      <div class="card">
        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (alertes().length === 0) {
          <p class="empty" style="color:#059669">✅ Tous les stocks sont au-dessus de leur seuil d'alerte.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>#</th><th>Produit</th><th>Quantité actuelle</th><th>Seuil alerte</th><th>Déficit</th></tr>
            </thead>
            <tbody>
              @for (s of alertes(); track s.id) {
                <tr>
                  <td>{{ s.id }}</td>
                  <td><strong>{{ s.produit }}</strong></td>
                  <td style="color:#dc2626;font-weight:600">{{ s.quantite | number }} kg</td>
                  <td>{{ s.seuil_alerte | number }} kg</td>
                  <td style="color:#dc2626">− {{ (s.seuil_alerte - s.quantite) | number }} kg</td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class AlertesComponent implements OnInit {
  alertes    = signal<any[]>([]);
  chargement = signal(true);
  constructor(private api: ApiService) {}
  ngOnInit(): void {
    this.api.getAlertes().subscribe({
      next: res => { this.alertes.set(res.alertes); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }
}
