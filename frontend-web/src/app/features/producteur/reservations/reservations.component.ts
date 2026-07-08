import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-producteur-reservations',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Réserver un entrepôt</h1>
        <span class="subtitle">Réservez de la place pour votre récolte avant de l'acheminer</span>
      </div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <!-- Entrepôts disponibles -->
      <div class="card">
        <h2>Entrepôts avec de la place</h2>
        @if (chargementEntrepots()) {
          <p class="loading">Chargement...</p>
        } @else if (entrepots().length === 0) {
          <p class="empty">Aucun entrepôt n'a de capacité disponible pour l'instant.</p>
        } @else {
          <div class="cards-grid">
            @for (e of entrepots(); track e.id) {
              <div class="product-card">
                <div class="product-header">
                  <h3>{{ e.nom_entrepot }}</h3>
                </div>
                <div class="product-info">
                  <div class="info-row"><span>Localisation</span><span>{{ e.localisation ?? '—' }}</span></div>
                  <div class="info-row"><span>Capacité disponible</span><span>{{ e.capacite_disponible | number }} kg</span></div>
                </div>
                <button class="btn-primary btn-full" (click)="ouvrirFormulaire(e)">Réserver ici</button>
              </div>
            }
          </div>
        }
      </div>

      <!-- Modal réservation -->
      @if (entrepotSelectionne(); as entrepot) {
        <div class="modal-overlay" (click)="fermerModal()">
          <div class="modal-box" (click)="$event.stopPropagation()">
            <h2>Réserver — {{ entrepot.nom_entrepot }}</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              Capacité disponible : {{ entrepot.capacite_disponible | number }} kg
            </p>

            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }

            <form [formGroup]="form" (ngSubmit)="reserver()">
              <div class="form-group">
                <label>Production concernée (optionnel)</label>
                <select formControlName="production_id">
                  <option [ngValue]="null">— Aucune —</option>
                  @for (p of productions(); track p.id) {
                    <option [ngValue]="p.id">{{ p.code_tracabilite }} — {{ p.type_culture }}</option>
                  }
                </select>
              </div>
              <div class="form-group">
                <label>Produit *</label>
                <input type="text" formControlName="produit" placeholder="ex : Maïs, Mil, Arachide..." />
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Quantité à réserver (kg) *</label>
                  <input type="number" formControlName="quantite_reservee" min="0.01" step="0.01" />
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Date de début *</label>
                  <input type="date" formControlName="date_debut" />
                </div>
                <div class="form-group">
                  <label>Date de fin (optionnel)</label>
                  <input type="date" formControlName="date_fin" />
                </div>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Envoi...' : 'Envoyer la demande' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModal()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Mes réservations -->
      <div class="card">
        <h2>Mes réservations</h2>
        @if (chargementReservations()) {
          <p class="loading">Chargement...</p>
        } @else if (reservations().length === 0) {
          <p class="empty">Aucune réservation pour l'instant.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Numéro</th><th>Entrepôt</th><th>Produit</th>
                <th>Quantité</th><th>Date début</th><th>Statut</th>
              </tr>
            </thead>
            <tbody>
              @for (r of reservations(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.entrepot?.nom_entrepot }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg</td>
                  <td>{{ r.date_debut | date:'dd/MM/yyyy' }}</td>
                  <td><span class="badge badge-{{ r.statut }}">{{ r.statut }}</span></td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>
    </div>
  `,
  styles: [`
    .modal-overlay {
      position:fixed;inset:0;background:rgba(0,0,0,.45);
      display:flex;align-items:center;justify-content:center;z-index:1000;
    }
    .modal-box {
      background:white;border-radius:10px;padding:2rem;
      width:min(480px,94vw);max-height:90vh;overflow-y:auto;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
    select {
      padding:.55rem .8rem;border:1px solid #e5e7eb;border-radius:6px;
      font-size:.9rem;width:100%;
    }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class ProducteurReservationsComponent implements OnInit {
  entrepots             = signal<any[]>([]);
  reservations           = signal<any[]>([]);
  productions            = signal<any[]>([]);
  chargementEntrepots    = signal(true);
  chargementReservations = signal(true);
  entrepotSelectionne    = signal<any | null>(null);
  loadingModal           = signal(false);
  message                = signal<string | null>(null);
  erreur                 = signal<string | null>(null);
  erreurModal            = signal<string | null>(null);
  form!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      production_id:     [null],
      produit:           ['', Validators.required],
      quantite_reservee: [null, [Validators.required, Validators.min(0.01)]],
      date_debut:        ['', Validators.required],
      date_fin:          [''],
    });

    this.chargerEntrepots();
    this.chargerReservations();
    this.api.getProductions().subscribe({
      next: res => this.productions.set(res.productions ?? []),
      error: () => {},
    });
  }

  chargerEntrepots(): void {
    this.chargementEntrepots.set(true);
    this.api.getEntrepotsDisponibles().subscribe({
      next: res => { this.entrepots.set(res.entrepots ?? []); this.chargementEntrepots.set(false); },
      error: () => this.chargementEntrepots.set(false),
    });
  }

  chargerReservations(): void {
    this.chargementReservations.set(true);
    this.api.getReservations().subscribe({
      next: res => { this.reservations.set(res.reservations ?? []); this.chargementReservations.set(false); },
      error: () => this.chargementReservations.set(false),
    });
  }

  ouvrirFormulaire(entrepot: any): void {
    this.entrepotSelectionne.set(entrepot);
    this.form.reset();
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.entrepotSelectionne.set(null); }

  reserver(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    const data = { ...this.form.value, entrepot_id: this.entrepotSelectionne()!.id };
    this.api.storeReservation(data).subscribe({
      next: () => {
        this.loadingModal.set(false);
        this.fermerModal();
        this.message.set('Demande de réservation envoyée.');
        this.chargerEntrepots();
        this.chargerReservations();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la réservation.');
        this.loadingModal.set(false);
      },
    });
  }
}
