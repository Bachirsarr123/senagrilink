import { Component, OnInit, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-commandes-disponibles',
  standalone: true,
  imports: [DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Commandes disponibles</h1>
        <p class="subtitle">Demandes d'acheteurs en attente — planifiez vos ventes</p>
      </div>
      <div class="card">
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (commandes().length === 0) {
          <p class="empty">Aucune commande en attente pour le moment.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Produit</th><th>Quantité demandée</th>
                <th>Prix proposé</th><th>Acheteur</th><th>Date</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandes(); track c.id) {
                <tr>
                  <td><strong>{{ c.produit }}</strong></td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix ? (c.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.acheteur?.utilisateur?.prenom }} {{ c.acheteur?.utilisateur?.nom }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
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
export class CommandesDisponiblesComponent implements OnInit {
  commandes  = signal<any[]>([]);
  chargement = signal(true);

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.api.getCommandesDisponibles().subscribe({
      next: res => { this.commandes.set(res.commandes_disponibles); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }
}
