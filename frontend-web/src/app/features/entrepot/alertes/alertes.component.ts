import { Component, OnInit, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';

@Component({
  selector: 'app-alertes',
  standalone: true,
  imports: [TableauResponsiveDirective, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>⚠️ Alertes de stock</h1>
        @if (!chargement() && !erreur()) {
          <span class="subtitle badge" [class.badge-probleme]="alertes().length > 0" [class.badge-disponible]="alertes().length === 0">
            {{ alertes().length }} produit(s) en dessous du seuil
          </span>
        }
      </div>
      <div class="card">
        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (erreur()) {
          <!-- P-21 : un échec de chargement ne doit jamais s'afficher « tout va bien ». -->
          <div class="alert alert-error" role="alert">{{ erreur() }}</div>
          <button class="btn-primary" (click)="charger()">Réessayer</button>
        }
        @else if (alertes().length === 0) {
          <p class="empty" style="color:#059669">✅ Tous les stocks sont au-dessus de leur seuil d'alerte.</p>
        } @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr><th>#</th><th>Produit</th><th>Disponible</th><th>Réservé</th><th>Seuil alerte</th><th>Déficit</th></tr>
            </thead>
            <tbody>
              @for (s of alertes(); track s.id) {
                <tr>
                  <td>{{ s.id }}</td>
                  <td><strong>{{ s.produit }}</strong></td>
                  <td style="color:#dc2626;font-weight:600">{{ s.quantite_disponible | number }} kg</td>
                  <td>{{ s.quantite_reservee | number }} kg</td>
                  <td>{{ s.seuil_alerte | number }} kg</td>
                  <td style="color:#dc2626">− {{ (s.seuil_alerte - s.quantite_disponible) | number }} kg</td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class AlertesComponent implements OnInit {
  alertes    = signal<any[]>([]);
  chargement = signal(true);
  erreur     = signal<string | null>(null);
  constructor(private api: ApiService) {}
  ngOnInit(): void { this.charger(); }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(null);
    this.api.getAlertes().subscribe({
      next: res => { this.alertes.set(res.alertes ?? []); this.chargement.set(false); },
      error: err => {
        this.erreur.set(err.status === 0
          ? 'Serveur injoignable : impossible de vérifier les seuils d\'alerte. Réessayez.'
          : err.error?.message ?? 'Impossible de charger les alertes de stock.');
        this.chargement.set(false);
      },
    });
  }
}
