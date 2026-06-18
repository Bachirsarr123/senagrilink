import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-stocks',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Gestion des stocks</h1>
        <div class="btn-group">
          <button class="btn-primary" (click)="modeFormulaire.set(modeFormulaire() === 'entree' ? null : 'entree')">
            + Entrée
          </button>
          <button class="btn-secondary" (click)="modeFormulaire.set(modeFormulaire() === 'sortie' ? null : 'sortie')">
            − Sortie
          </button>
        </div>
      </div>

      @if (modeFormulaire() === 'entree') {
        <div class="card form-card">
          <h2>Nouvelle entrée de stock</h2>
          @if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
          <form [formGroup]="formEntree" (ngSubmit)="enregistrerEntree()">
            <div class="form-row">
              <div class="form-group">
                <label>Produit *</label>
                <input type="text" formControlName="produit" placeholder="Mil, Riz..." />
              </div>
              <div class="form-group">
                <label>Quantité (kg) *</label>
                <input type="number" formControlName="quantite" min="0.01" />
              </div>
              <div class="form-group">
                <label>Seuil d'alerte (kg)</label>
                <input type="number" formControlName="seuil_alerte" min="0" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>ID Production (traçabilité)</label>
                <input type="number" formControlName="production_id" placeholder="Optionnel" />
              </div>
              <div class="form-group">
                <label>Date d'entrée</label>
                <input type="date" formControlName="date_entree" />
              </div>
            </div>
            <button type="submit" class="btn-primary" [disabled]="loading()">
              {{ loading() ? 'Enregistrement...' : 'Enregistrer l\'entrée' }}
            </button>
          </form>
        </div>
      }

      @if (modeFormulaire() === 'sortie') {
        <div class="card form-card">
          <h2>Enregistrer une sortie de stock</h2>
          @if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
          <form [formGroup]="formSortie" (ngSubmit)="enregistrerSortie()">
            <div class="form-row">
              <div class="form-group">
                <label>ID du stock *</label>
                <input type="number" formControlName="stock_id" placeholder="Sélectionner un stock" />
              </div>
              <div class="form-group">
                <label>Quantité à sortir (kg) *</label>
                <input type="number" formControlName="quantite_sortie" min="0.01" />
              </div>
            </div>
            <button type="submit" class="btn-secondary" [disabled]="loading()">
              {{ loading() ? 'Traitement...' : 'Enregistrer la sortie' }}
            </button>
          </form>
        </div>
      }

      <div class="card">
        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (stocks().length === 0) { <p class="empty">Aucun stock enregistré.</p> }
        @else {
          <table class="table">
            <thead>
              <tr>
                <th>#</th><th>Produit</th><th>Quantité</th>
                <th>Seuil alerte</th><th>Date entrée</th><th>Statut</th>
              </tr>
            </thead>
            <tbody>
              @for (s of stocks(); track s.id) {
                <tr [class.row-alert]="s.seuil_alerte && s.quantite <= s.seuil_alerte">
                  <td>{{ s.id }}</td>
                  <td><strong>{{ s.produit }}</strong></td>
                  <td>{{ s.quantite | number }} kg</td>
                  <td>{{ s.seuil_alerte ? (s.seuil_alerte | number) + ' kg' : '—' }}</td>
                  <td>{{ s.date_entree | date:'dd/MM/yyyy' }}</td>
                  <td><span class="badge badge-{{ s.statut }}">{{ s.statut }}</span></td>
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
export class StocksComponent implements OnInit {
  stocks         = signal<any[]>([]);
  modeFormulaire = signal<'entree' | 'sortie' | null>(null);
  chargement     = signal(true);
  loading        = signal(false);
  erreur         = signal<string | null>(null);
  formEntree: FormGroup;
  formSortie: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.formEntree = this.fb.group({
      produit:       ['', Validators.required],
      quantite:      [null, [Validators.required, Validators.min(0.01)]],
      seuil_alerte:  [null],
      production_id: [null],
      date_entree:   [''],
    });
    this.formSortie = this.fb.group({
      stock_id:       [null, Validators.required],
      quantite_sortie:[null, [Validators.required, Validators.min(0.01)]],
    });
  }

  ngOnInit(): void {
    this.chargerStocks();
  }

  chargerStocks(): void {
    this.api.getStocks().subscribe({
      next: res => { this.stocks.set(res.stocks); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  enregistrerEntree(): void {
    if (this.formEntree.invalid) { this.formEntree.markAllAsTouched(); return; }
    this.loading.set(true); this.erreur.set(null);
    this.api.entreeStock(this.formEntree.value).subscribe({
      next: () => { this.chargerStocks(); this.formEntree.reset(); this.loading.set(false); this.modeFormulaire.set(null); },
      error: err => { this.erreur.set(err.error?.message ?? 'Erreur.'); this.loading.set(false); },
    });
  }

  enregistrerSortie(): void {
    if (this.formSortie.invalid) { this.formSortie.markAllAsTouched(); return; }
    this.loading.set(true); this.erreur.set(null);
    this.api.sortieStock(this.formSortie.value).subscribe({
      next: () => { this.chargerStocks(); this.formSortie.reset(); this.loading.set(false); this.modeFormulaire.set(null); },
      error: err => { this.erreur.set(err.error?.message ?? 'Erreur.'); this.loading.set(false); },
    });
  }
}
