import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-tracabilite',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, RouterLink],
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

      @if (resultat(); as r) {
        <!-- Étape 1 : Production -->
        <div class="card">
          <h2 style="display:flex;align-items:center;gap:.5rem">
            <span style="font-size:1.4rem">🌱</span> Production
          </h2>
          <div class="stat-row">
            <span>Code</span>
            <code class="code-lot">{{ r.code_tracabilite }}</code>
          </div>
          @if (r.etape_production.producteur; as producteur) {
            <div class="stat-row">
              <span>Producteur</span>
              <strong>{{ producteur.prenom }} {{ producteur.nom }}</strong>
            </div>
            <div class="stat-row">
              <span>Région</span>
              <span>{{ producteur.region ?? '—' }}</span>
            </div>
          }
          <div class="stat-row">
            <span>Type de culture</span>
            <strong>{{ r.etape_production.type_culture ?? '—' }}</strong>
          </div>
          <div class="stat-row">
            <span>Date récolte</span>
            <span>{{ r.etape_production.date_recolte | date:'dd/MM/yyyy' }}</span>
          </div>
          <div class="stat-row">
            <span>Quantité estimée</span>
            <span>{{ r.etape_production.quantite_estimee ?? '—' }} kg</span>
          </div>
          <div class="stat-row">
            <span>Quantité réelle</span>
            <span>{{ r.etape_production.quantite_reelle ?? '—' }} kg</span>
          </div>
          <div class="stat-row">
            <span>Statut</span>
            <span class="badge badge-{{ r.etape_production.statut }}">
              {{ r.etape_production.statut }}
            </span>
          </div>
        </div>

        <!-- Étape 2+ : une carte par entrepôt où le lot est passé -->
        @if (r.etapes_stockage.length === 0) {
          <div class="card" style="border-left:3px solid #e5e7eb">
            <p class="text-muted" style="margin:0">
              Ce lot n'a pas encore été réceptionné en entrepôt.
            </p>
          </div>
        } @else {
          @for (etape of r.etapes_stockage; track $index) {
            <div class="card">
              <h2 style="display:flex;align-items:center;gap:.5rem">
                <span style="font-size:1.4rem">🏭</span> Stockage en entrepôt
              </h2>
              @if (etape.entrepot) {
                <div class="stat-row">
                  <span>Entrepôt</span>
                  <strong>{{ etape.entrepot.nom }}</strong>
                </div>
                <div class="stat-row">
                  <span>Localisation</span>
                  <span>{{ etape.entrepot.localisation ?? '—' }}</span>
                </div>
              }
              <div class="stat-row">
                <span>Produit</span>
                <span>{{ etape.produit }}</span>
              </div>
              <div class="stat-row">
                <span>Quantité</span>
                <span>{{ etape.quantite }} kg</span>
              </div>
              <div class="stat-row">
                <span>Date entrée</span>
                <span>{{ etape.date_entree | date:'dd/MM/yyyy' }}</span>
              </div>
              @if (etape.date_sortie) {
                <div class="stat-row">
                  <span>Date sortie</span>
                  <span>{{ etape.date_sortie | date:'dd/MM/yyyy' }}</span>
                </div>
              }
              <div class="stat-row">
                <span>Statut</span>
                <span class="badge badge-{{ etape.statut }}">{{ etape.statut }}</span>
              </div>
            </div>

            @if (etape.commandes.length === 0) {
              <div class="card" style="border-left:3px solid #e5e7eb">
                <p class="text-muted" style="margin:0">Aucune commande liée à ce lot.</p>
              </div>
            } @else {
              @for (commande of etape.commandes; track $index) {
                <div class="card">
                  <h2 style="display:flex;align-items:center;gap:.5rem">
                    <span style="font-size:1.4rem">🛒</span> Commande associée
                  </h2>
                  <div class="stat-row">
                    <span>Numéro</span>
                    <code class="code-lot">{{ commande.numero_commande }}</code>
                  </div>
                  @if (commande.acheteur; as acheteur) {
                    <div class="stat-row">
                      <span>Acheteur</span>
                      <strong>{{ acheteur.prenom }} {{ acheteur.nom }}</strong>
                    </div>
                  }
                  <div class="stat-row">
                    <span>Produit</span>
                    <span>{{ commande.produit }}</span>
                  </div>
                  <div class="stat-row">
                    <span>Quantité</span>
                    <span>{{ commande.quantite }} kg</span>
                  </div>
                  <div class="stat-row">
                    <span>Prix</span>
                    <span>{{ commande.prix ? commande.prix + ' FCFA' : '—' }}</span>
                  </div>
                  <div class="stat-row">
                    <span>Date commande</span>
                    <span>{{ commande.date_commande | date:'dd/MM/yyyy' }}</span>
                  </div>
                  <div class="stat-row">
                    <span>Statut</span>
                    <span class="badge badge-{{ commande.statut }}">{{ commande.statut }}</span>
                  </div>
                </div>

                @if (!commande.livraison) {
                  <div class="card" style="border-left:3px solid #e5e7eb">
                    <p class="text-muted" style="margin:0">Livraison pas encore créée pour cette commande.</p>
                  </div>
                } @else {
                  <div class="card">
                    <h2 style="display:flex;align-items:center;gap:.5rem">
                      <span style="font-size:1.4rem">🚛</span> Livraison
                    </h2>
                    <div class="stat-row">
                      <span>Numéro</span>
                      <code class="code-lot">{{ commande.livraison.numero_livraison }}</code>
                    </div>
                    <div class="stat-row">
                      <span>Origine</span>
                      <span>{{ commande.livraison.origine ?? '—' }}</span>
                    </div>
                    <div class="stat-row">
                      <span>Destination</span>
                      <span>{{ commande.livraison.destination ?? '—' }}</span>
                    </div>
                    <div class="stat-row">
                      <span>Date départ</span>
                      <span>{{ commande.livraison.date_depart | date:'dd/MM/yyyy HH:mm' }}</span>
                    </div>
                    <div class="stat-row">
                      <span>Date livraison</span>
                      <span>
                        {{ commande.livraison.date_livraison
                           ? (commande.livraison.date_livraison | date:'dd/MM/yyyy HH:mm')
                           : '—' }}
                      </span>
                    </div>
                    <div class="stat-row">
                      <span>Statut</span>
                      <span class="badge badge-{{ commande.livraison.statut }}">
                        {{ commande.livraison.statut }}
                      </span>
                    </div>
                    @if (commande.livraison.transporteur; as transporteur) {
                      <div class="stat-row">
                        <span>Transporteur</span>
                        <strong>{{ transporteur.prenom }} {{ transporteur.nom }}</strong>
                      </div>
                      <div class="stat-row">
                        <span>Véhicule</span>
                        <span>{{ transporteur.type_vehicule ?? '—' }}</span>
                      </div>
                      <div class="stat-row">
                        <span>Zone</span>
                        <span>{{ transporteur.zone ?? '—' }}</span>
                      </div>
                    } @else {
                      <div class="stat-row">
                        <span>Transporteur</span>
                        <span class="text-muted">Pas encore assigné</span>
                      </div>
                    }
                    @if (commande.livraison.statut === 'en_cours' && peutSuivreEnDirect()) {
                      <a class="btn-secondary btn-sm" style="margin-top:.75rem;display:inline-block"
                         [routerLink]="['/suivi-livraison', commande.livraison.id]">
                        📍 Suivre en direct
                      </a>
                    }
                  </div>
                }
              }
            }
          }
        }
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class TracabiliteComponent {
  private api  = inject(ApiService);
  private auth = inject(AuthService);
  private fb   = inject(FormBuilder);

  form:     FormGroup;
  resultat  = signal<any | null>(null);
  loading   = signal(false);
  erreur    = signal<string | null>(null);

  // Le suivi en direct n'est accessible qu'au producteur et à l'acheteur concernés (cf. app.routes.ts).
  peutSuivreEnDirect = computed(() =>
    this.auth.role() === 'producteur' || this.auth.role() === 'acheteur_gros'
  );

  constructor() {
    this.form = this.fb.group({ code: ['', Validators.required] });
  }

  rechercher(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading.set(true);
    this.erreur.set(null);
    this.resultat.set(null);

    const code = (this.form.value.code as string).trim().toUpperCase();
    this.api.getTracabilite(code).subscribe({
      next: res => { this.resultat.set(res.tracabilite); this.loading.set(false); },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Code introuvable ou erreur serveur.');
        this.loading.set(false);
      },
    });
  }
}
