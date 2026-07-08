import { Component, OnInit, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-planifier-ventes',
  standalone: true,
  imports: [DatePipe, DecimalPipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Planifier mes ventes</h1>
        <span class="subtitle">Suivez vos récoltes et consultez les demandes d'acheteurs</span>
      </div>

      <!-- Récoltes en attente -->
      <div class="card">
        <h2>Mes récoltes en attente</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:1rem">
          Une récolte n'apparaît dans le catalogue des acheteurs qu'une fois réceptionnée et
          validée en entrepôt. <a routerLink="/producteur/reservations">Réservez de la place en entrepôt</a>
          pour lancer le processus.
        </p>

        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (enAttente().length === 0) {
          <p class="empty">Aucune récolte en attente — toutes sont déjà en entrepôt.</p>
        } @else {
          <div class="cards-grid">
            @for (p of enAttente(); track p.id) {
              <div class="product-card">
                <div class="product-header">
                  <h3>{{ p.type_culture }}</h3>
                  <span class="badge badge-en_attente">en_attente</span>
                </div>
                <div class="product-info">
                  <div class="info-row">
                    <span>Code</span>
                    <code>{{ p.code_tracabilite }}</code>
                  </div>
                  <div class="info-row">
                    <span>Récolte</span>
                    <span>{{ p.date_recolte | date:'dd/MM/yyyy' }}</span>
                  </div>
                  <div class="info-row">
                    <span>Qté estimée</span>
                    <span>{{ p.quantite_estimee | number }} kg</span>
                  </div>
                  @if (p.quantite_reelle) {
                    <div class="info-row">
                      <span>Qté réelle</span>
                      <span>{{ p.quantite_reelle | number }} kg</span>
                    </div>
                  }
                </div>
                <a class="btn-primary btn-full" routerLink="/producteur/reservations">
                  Réserver un entrepôt
                </a>
              </div>
            }
          </div>
        }
      </div>

      <!-- Productions déjà en stock -->
      <div class="card">
        <h2>Mes productions en entrepôt</h2>
        @if (disponibles().length === 0) {
          <p class="empty">Aucune production en entrepôt pour l'instant.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Code</th><th>Culture</th><th>Date récolte</th>
                <th>Quantité</th><th>Statut</th>
              </tr>
            </thead>
            <tbody>
              @for (p of disponibles(); track p.id) {
                <tr>
                  <td><code class="code-lot">{{ p.code_tracabilite }}</code></td>
                  <td><strong>{{ p.type_culture }}</strong></td>
                  <td>{{ p.date_recolte | date:'dd/MM/yyyy' }}</td>
                  <td>{{ p.quantite_estimee | number }} kg</td>
                  <td><span class="badge badge-disponible">disponible</span></td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Demandes d'acheteurs disponibles -->
      <div class="card">
        <h2>Demandes d'acheteurs en cours</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:1rem">
          Commandes passées par les acheteurs et en attente de traitement.
        </p>
        @if (chargementDemandes()) {
          <p class="loading">Chargement des demandes...</p>
        } @else if (demandes().length === 0) {
          <p class="empty">Aucune demande disponible pour le moment.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Produit</th><th>Quantité</th><th>Prix</th><th>Date</th><th>Statut</th></tr>
            </thead>
            <tbody>
              @for (d of demandes(); track d.id) {
                <tr>
                  <td><code class="code-lot">{{ d.numero_commande }}</code></td>
                  <td>{{ d.produit }}</td>
                  <td>{{ d.quantite | number }} kg</td>
                  <td>{{ d.prix ? (d.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ d.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td><span class="badge badge-{{ d.statut }}">{{ d.statut }}</span></td>
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
export class PlanifierVentesComponent implements OnInit {
  enAttente        = signal<any[]>([]);
  disponibles      = signal<any[]>([]);
  demandes         = signal<any[]>([]);
  chargement       = signal(true);
  chargementDemandes = signal(true);

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.chargerProductions();
    this.chargerDemandes();
  }

  chargerProductions(): void {
    this.chargement.set(true);
    this.api.getProductions().subscribe({
      next: res => {
        const all: any[] = res.productions ?? [];
        this.enAttente.set(all.filter(p => p.statut === 'en_attente'));
        this.disponibles.set(all.filter(p => p.statut === 'disponible'));
        this.chargement.set(false);
      },
      error: () => this.chargement.set(false),
    });
  }

  chargerDemandes(): void {
    this.chargementDemandes.set(true);
    this.api.getCommandesDisponibles().subscribe({
      next: res => { this.demandes.set(res.commandes ?? []); this.chargementDemandes.set(false); },
      error: () => this.chargementDemandes.set(false),
    });
  }

}
