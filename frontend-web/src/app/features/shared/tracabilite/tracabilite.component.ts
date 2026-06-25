import { Component, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-tracabilite',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Traçabilité d'un lot</h1>
        <span class="subtitle">Parcours complet d'un produit par son code LOT</span>
      </div>

      <div class="card form-card">
        <form [formGroup]="form" (ngSubmit)="rechercher()">
          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label>Code de traçabilité *</label>
              <input type="text" formControlName="code"
                     placeholder="ex : LOT-2026-00001"
                     style="font-family:monospace;text-transform:uppercase" />
            </div>
            <div style="display:flex;align-items:flex-end;padding-bottom:.75rem">
              <button type="submit" class="btn-primary" [disabled]="loading()">
                {{ loading() ? 'Recherche...' : 'Rechercher' }}
              </button>
            </div>
          </div>
        </form>
      </div>

      @if (erreur()) {
        <div class="alert alert-error">{{ erreur() }}</div>
      }

      @if (resultat()) {
        <!-- Étape 1 : Production -->
        <div class="card">
          <h2 style="display:flex;align-items:center;gap:.5rem">
            <span style="font-size:1.4rem">🌱</span> Production
          </h2>
          <div class="stat-row">
            <span>Code</span>
            <code class="code-lot">{{ resultat()!.production.code_tracabilite }}</code>
          </div>
          <div class="stat-row">
            <span>Type de culture</span>
            <strong>{{ resultat()!.production.type_culture ?? '—' }}</strong>
          </div>
          <div class="stat-row">
            <span>Date récolte</span>
            <span>{{ resultat()!.production.date_recolte | date:'dd/MM/yyyy' }}</span>
          </div>
          <div class="stat-row">
            <span>Quantité estimée</span>
            <span>{{ resultat()!.production.quantite_estimee ?? '—' }} kg</span>
          </div>
          <div class="stat-row">
            <span>Quantité réelle</span>
            <span>{{ resultat()!.production.quantite_reelle ?? '—' }} kg</span>
          </div>
          <div class="stat-row">
            <span>Statut</span>
            <span class="badge badge-{{ resultat()!.production.statut }}">
              {{ resultat()!.production.statut }}
            </span>
          </div>
        </div>

        <!-- Étape 2 : Stock -->
        @if (resultat()!.stock) {
          <div class="card">
            <h2 style="display:flex;align-items:center;gap:.5rem">
              <span style="font-size:1.4rem">🏭</span> Stockage en entrepôt
            </h2>
            @if (resultat()!.stock!.entrepot) {
              <div class="stat-row">
                <span>Entrepôt</span>
                <strong>{{ resultat()!.stock!.entrepot.nom_entrepot }}</strong>
              </div>
              <div class="stat-row">
                <span>Localisation</span>
                <span>{{ resultat()!.stock!.entrepot.localisation ?? '—' }}</span>
              </div>
            }
            <div class="stat-row">
              <span>Produit</span>
              <span>{{ resultat()!.stock!.produit }}</span>
            </div>
            <div class="stat-row">
              <span>Quantité</span>
              <span>{{ resultat()!.stock!.quantite }} kg</span>
            </div>
            <div class="stat-row">
              <span>Date entrée</span>
              <span>{{ resultat()!.stock!.date_entree | date:'dd/MM/yyyy' }}</span>
            </div>
            @if (resultat()!.stock!.date_sortie) {
              <div class="stat-row">
                <span>Date sortie</span>
                <span>{{ resultat()!.stock!.date_sortie | date:'dd/MM/yyyy' }}</span>
              </div>
            }
            <div class="stat-row">
              <span>Statut</span>
              <span class="badge badge-{{ resultat()!.stock!.statut }}">
                {{ resultat()!.stock!.statut }}
              </span>
            </div>
          </div>
        } @else {
          <div class="card" style="border-left:3px solid #e5e7eb">
            <p class="text-muted" style="margin:0">
              Ce lot n'a pas encore été réceptionné en entrepôt.
            </p>
          </div>
        }

        <!-- Étape 3 : Commande -->
        @if (resultat()!.commande) {
          <div class="card">
            <h2 style="display:flex;align-items:center;gap:.5rem">
              <span style="font-size:1.4rem">🛒</span> Commande associée
            </h2>
            <div class="stat-row">
              <span>Numéro</span>
              <code class="code-lot">{{ resultat()!.commande!.numero_commande }}</code>
            </div>
            <div class="stat-row">
              <span>Produit</span>
              <span>{{ resultat()!.commande!.produit }}</span>
            </div>
            <div class="stat-row">
              <span>Quantité</span>
              <span>{{ resultat()!.commande!.quantite }} kg</span>
            </div>
            <div class="stat-row">
              <span>Prix</span>
              <span>{{ resultat()!.commande!.prix ? resultat()!.commande!.prix + ' FCFA' : '—' }}</span>
            </div>
            <div class="stat-row">
              <span>Date commande</span>
              <span>{{ resultat()!.commande!.date_commande | date:'dd/MM/yyyy' }}</span>
            </div>
            <div class="stat-row">
              <span>Statut</span>
              <span class="badge badge-{{ resultat()!.commande!.statut }}">
                {{ resultat()!.commande!.statut }}
              </span>
            </div>
          </div>
        } @else if (resultat()!.stock) {
          <div class="card" style="border-left:3px solid #e5e7eb">
            <p class="text-muted" style="margin:0">Aucune commande liée à ce lot.</p>
          </div>
        }

        <!-- Étape 4 : Livraison -->
        @if (resultat()!.livraison) {
          <div class="card">
            <h2 style="display:flex;align-items:center;gap:.5rem">
              <span style="font-size:1.4rem">🚛</span> Livraison
            </h2>
            <div class="stat-row">
              <span>Numéro</span>
              <code class="code-lot">{{ resultat()!.livraison!.numero_livraison }}</code>
            </div>
            <div class="stat-row">
              <span>Origine</span>
              <span>{{ resultat()!.livraison!.origine ?? '—' }}</span>
            </div>
            <div class="stat-row">
              <span>Destination</span>
              <span>{{ resultat()!.livraison!.destination ?? '—' }}</span>
            </div>
            <div class="stat-row">
              <span>Date départ</span>
              <span>{{ resultat()!.livraison!.date_depart | date:'dd/MM/yyyy HH:mm' }}</span>
            </div>
            <div class="stat-row">
              <span>Date livraison</span>
              <span>
                {{ resultat()!.livraison!.date_livraison
                   ? (resultat()!.livraison!.date_livraison | date:'dd/MM/yyyy HH:mm')
                   : '—' }}
              </span>
            </div>
            <div class="stat-row">
              <span>Statut</span>
              <span class="badge badge-{{ resultat()!.livraison!.statut }}">
                {{ resultat()!.livraison!.statut }}
              </span>
            </div>
          </div>
        } @else if (resultat()!.commande) {
          <div class="card" style="border-left:3px solid #e5e7eb">
            <p class="text-muted" style="margin:0">Livraison pas encore créée pour cette commande.</p>
          </div>
        }
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class TracabiliteComponent {
  form:     FormGroup;
  resultat  = signal<any | null>(null);
  loading   = signal(false);
  erreur    = signal<string | null>(null);

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.form = this.fb.group({ code: ['', Validators.required] });
  }

  rechercher(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading.set(true);
    this.erreur.set(null);
    this.resultat.set(null);

    const code = (this.form.value.code as string).trim().toUpperCase();
    this.api.getTracabilite(code).subscribe({
      next: res => { this.resultat.set(res); this.loading.set(false); },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Code introuvable ou erreur serveur.');
        this.loading.set(false);
      },
    });
  }
}
