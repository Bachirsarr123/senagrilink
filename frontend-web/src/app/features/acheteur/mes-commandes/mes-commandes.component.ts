import { Component, OnInit, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-mes-commandes',
  standalone: true,
  imports: [DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Mes commandes</h1></div>
      <div class="card">
        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (commandes().length === 0) { <p class="empty">Aucune commande passée.</p> }
        @else {
          <table class="table">
            <thead>
              <tr>
                <th>Numéro</th><th>Produit</th><th>Qté</th>
                <th>Prix</th><th>Date</th><th>Statut</th><th>Livraison</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandes(); track c.id) {
                <tr>
                  <td><code>{{ c.numero_commande }}</code></td>
                  <td>{{ c.produit }}</td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix ? (c.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>
                    <span class="badge badge-{{ c.statut }}">{{ c.statut }}</span>
                    @if (c.alerte_livraison) {
                      <span class="badge badge-probleme" title="Problème de livraison signalé">⚠️</span>
                    }
                  </td>
                  <td>
                    @if (c.livraison) {
                      <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut }}</span>
                    } @else { <span class="text-muted">—</span> }
                  </td>
                  <td>
                    @if (c.statut === 'en_attente') {
                      <button class="btn-danger btn-sm" (click)="annuler(c.id)">Annuler</button>
                    }
                  </td>
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
export class MesCommandesComponent implements OnInit {
  commandes  = signal<any[]>([]);
  chargement = signal(true);

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.api.getHistoriqueCommandes().subscribe({
      next: res => { this.commandes.set(res.commandes); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  annuler(id: number): void {
    if (!confirm('Confirmer l\'annulation de cette commande ?')) return;
    this.api.annulerCommande(id).subscribe({
      next: () => this.commandes.update(list => list.map(c => c.id === id ? { ...c, statut: 'annulee' } : c)),
    });
  }
}
