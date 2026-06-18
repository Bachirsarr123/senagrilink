import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-catalogue',
  standalone: true,
  imports: [ReactiveFormsModule, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Catalogue des produits</h1>
        <p class="subtitle">{{ produits().length }} produit(s) disponible(s)</p>
      </div>

      @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }
      @if (erreurCommande()) { <div class="alert alert-error">{{ erreurCommande() }}</div> }

      @if (chargement()) { <p class="loading">Chargement...</p> }
      @else if (produits().length === 0) { <p class="empty">Aucun produit disponible pour le moment.</p> }
      @else {
        <div class="cards-grid">
          @for (p of produits(); track p.stock_id) {
            <div class="product-card">
              <div class="product-header">
                <h3>{{ p.produit }}</h3>
                <span class="badge badge-disponible">Disponible</span>
              </div>
              <div class="product-info">
                <div class="info-row">
                  <span>📦 Stock disponible</span>
                  <strong>{{ p.quantite | number }} kg</strong>
                </div>
                <div class="info-row">
                  <span>🏪 Entrepôt</span>
                  <strong>{{ p.entrepot?.nom_entrepot }}</strong>
                </div>
                <div class="info-row">
                  <span>📍 Localisation</span>
                  <span>{{ p.entrepot?.localisation }}</span>
                </div>
                @if (p.production) {
                  <div class="info-row">
                    <span>🔍 Traçabilité</span>
                    <code>{{ p.production.code_tracabilite }}</code>
                  </div>
                }
              </div>

              @if (commandeOuverte() === p.stock_id) {
                <form [formGroup]="formCommande" (ngSubmit)="passerCommande(p)" class="commande-form">
                  <div class="form-group">
                    <label>Quantité souhaitée (kg)</label>
                    <input type="number" formControlName="quantite" [max]="p.quantite" min="0.01" />
                  </div>
                  <div class="form-group">
                    <label>Prix proposé (FCFA, optionnel)</label>
                    <input type="number" formControlName="prix" min="0" />
                  </div>
                  <div class="btn-group">
                    <button type="submit" class="btn-primary" [disabled]="loadingCmd()">
                      {{ loadingCmd() ? '...' : 'Confirmer la commande' }}
                    </button>
                    <button type="button" class="btn-ghost" (click)="commandeOuverte.set(null)">Annuler</button>
                  </div>
                </form>
              } @else {
                <button class="btn-primary btn-full" (click)="ouvrirCommande(p)">
                  Commander
                </button>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class CatalogueComponent implements OnInit {
  produits        = signal<any[]>([]);
  commandeOuverte = signal<number | null>(null);
  chargement      = signal(true);
  loadingCmd      = signal(false);
  succes          = signal<string | null>(null);
  erreurCommande  = signal<string | null>(null);
  formCommande: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.formCommande = this.fb.group({
      quantite: [null, [Validators.required, Validators.min(0.01)]],
      prix:     [null],
    });
  }

  ngOnInit(): void {
    this.api.getCatalogue().subscribe({
      next: res => { this.produits.set(res.catalogue); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  ouvrirCommande(produit: any): void {
    this.commandeOuverte.set(produit.stock_id);
    this.formCommande.reset();
    this.erreurCommande.set(null);
  }

  passerCommande(produit: any): void {
    if (this.formCommande.invalid) { this.formCommande.markAllAsTouched(); return; }
    this.loadingCmd.set(true); this.erreurCommande.set(null);

    this.api.passerCommande({ stock_id: produit.stock_id, ...this.formCommande.value }).subscribe({
      next: res => {
        this.succes.set(`Commande ${res.commande.numero_commande} passée avec succès !`);
        this.commandeOuverte.set(null);
        this.loadingCmd.set(false);
        setTimeout(() => this.succes.set(null), 4000);
      },
      error: err => {
        this.erreurCommande.set(err.error?.message ?? 'Erreur lors de la commande.');
        this.loadingCmd.set(false);
      },
    });
  }
}
