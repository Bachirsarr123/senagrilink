import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-entrepot-reservations',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Réservations</h1>
        <span class="subtitle">Confirmation, acheminement et réception des marchandises réservées</span>
      </div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <!-- En attente de confirmation -->
      <div class="card">
        <h2>En attente de confirmation</h2>
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (enAttente().length === 0) {
          <p class="empty">Aucune réservation en attente.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Producteur</th><th>Produit</th><th>Quantité</th><th>Date début</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (r of enAttente(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg</td>
                  <td>{{ r.date_debut | date:'dd/MM/yyyy' }}</td>
                  <td>
                    <button class="btn-success btn-sm" [disabled]="actionEnCours() === r.id" (click)="confirmer(r.id)">
                      Confirmer
                    </button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Confirmées, en attente d'un transporteur -->
      <div class="card">
        <h2>À assigner à un transporteur</h2>
        @if (confirmees().length === 0) {
          <p class="empty">Aucune réservation à assigner.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Producteur</th><th>Produit</th><th>Quantité</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (r of confirmees(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg</td>
                  <td>
                    <button class="btn-secondary btn-sm" (click)="ouvrirAssignation(r)">Assigner un transporteur</button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Confirmées, transporteur déjà assigné -->
      <div class="card">
        <h2>En cours d'acheminement</h2>
        @if (enTransit().length === 0) {
          <p class="empty">Aucune réservation en cours d'acheminement.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Producteur</th><th>Produit</th><th>Quantité</th><th>Transporteur</th></tr>
            </thead>
            <tbody>
              @for (r of enTransit(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg</td>
                  <td>
                    {{ r.transporteur?.utilisateur?.prenom }} {{ r.transporteur?.utilisateur?.nom }}
                    {{ r.transporteur?.type_vehicule ? '(' + r.transporteur.type_vehicule + ')' : '' }}
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Arrivées à l'entrepôt, à valider -->
      <div class="card">
        <h2>Marchandises arrivées — à valider</h2>
        @if (arrivees().length === 0) {
          <p class="empty">Aucune marchandise en attente de validation.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Producteur</th><th>Produit</th><th>Qté réservée</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (r of arrivees(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg</td>
                  <td>
                    <button class="btn-success btn-sm" (click)="ouvrirValidation(r)">Valider la marchandise</button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Modal assignation transporteur -->
      @if (reservationAAssigner(); as r) {
        <div class="modal-overlay" (click)="fermerModals()">
          <div class="modal-box" (click)="$event.stopPropagation()">
            <h2>Assigner un transporteur</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              Réservation <code class="code-lot">{{ r.numero_reservation }}</code>
            </p>
            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }
            <form [formGroup]="formAssignation" (ngSubmit)="assigner()">
              <div class="form-group">
                <label>Transporteur *</label>
                <select formControlName="transporteur_id">
                  <option [ngValue]="null">— Choisir —</option>
                  @for (t of transporteurs(); track t.id) {
                    <option [ngValue]="t.id">
                      {{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }}
                      {{ t.type_vehicule ? '(' + t.type_vehicule + ')' : '' }}
                    </option>
                  }
                </select>
                @if (formAssignation.get('transporteur_id')?.invalid && formAssignation.get('transporteur_id')?.touched) {
                  <span class="field-error">Choisis un transporteur.</span>
                }
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Envoi...' : 'Assigner' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModals()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Modal validation marchandise -->
      @if (reservationAValider(); as r) {
        <div class="modal-overlay" (click)="fermerModals()">
          <div class="modal-box" (click)="$event.stopPropagation()">
            <h2>Valider la marchandise</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              Réservation <code class="code-lot">{{ r.numero_reservation }}</code> —
              {{ r.produit }} ({{ r.quantite_reservee | number }} kg réservés)
            </p>
            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }
            <form [formGroup]="formValidation" (ngSubmit)="valider()">
              <div class="form-group">
                <label>Quantité réellement reçue (kg) *</label>
                <input type="number" formControlName="quantite_reelle" min="0.01" step="0.01" />
              </div>
              <div class="form-group">
                <label>Observation qualité (optionnel)</label>
                <textarea formControlName="observation" rows="3" placeholder="État du lot, qualité constatée..."></textarea>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Envoi...' : 'Valider et enregistrer en stock' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModals()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }
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
    select, textarea {
      padding:.55rem .8rem;border:1px solid #e5e7eb;border-radius:6px;
      font-size:.9rem;width:100%;font-family:inherit;
    }
    .field-error {
      display:block;color:#dc2626;font-size:.8rem;margin-top:.3rem;
    }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class EntrepotReservationsComponent implements OnInit {
  toutes             = signal<any[]>([]);
  transporteurs       = signal<any[]>([]);
  chargement          = signal(true);
  actionEnCours       = signal<number | null>(null);
  reservationAAssigner = signal<any | null>(null);
  reservationAValider   = signal<any | null>(null);
  loadingModal        = signal(false);
  message             = signal<string | null>(null);
  erreur               = signal<string | null>(null);
  erreurModal          = signal<string | null>(null);
  formAssignation!: FormGroup;
  formValidation!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formAssignation = this.fb.group({ transporteur_id: [null, Validators.required] });
    this.formValidation = this.fb.group({
      quantite_reelle: [null, [Validators.required, Validators.min(0.01)]],
      observation:     [''],
    });

    this.charger();
    this.api.getTransporteurs().subscribe({
      next: res => this.transporteurs.set(res.transporteurs ?? []),
      error: () => {},
    });
  }

  enAttente = () => this.toutes().filter(r => r.statut === 'en_attente');
  confirmees = () => this.toutes().filter(r => r.statut === 'confirmee' && !r.transporteur_id);
  enTransit = () => this.toutes().filter(r => r.statut === 'confirmee' && !!r.transporteur_id);
  arrivees = () => this.toutes().filter(r => r.statut === 'arrivee_entrepot');

  charger(): void {
    this.chargement.set(true);
    this.api.getReservations().subscribe({
      next: res => { this.toutes.set(res.reservations ?? []); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  confirmer(id: number): void {
    this.actionEnCours.set(id);
    this.erreur.set(null);
    this.api.confirmerReservation(id).subscribe({
      next: () => {
        this.message.set('Réservation confirmée. Le producteur a été notifié.');
        this.actionEnCours.set(null);
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de la confirmation.');
        this.actionEnCours.set(null);
      },
    });
  }

  ouvrirAssignation(r: any): void {
    this.reservationAAssigner.set(r);
    this.formAssignation.reset();
    this.erreurModal.set(null);
  }

  ouvrirValidation(r: any): void {
    this.reservationAValider.set(r);
    this.formValidation.reset({ quantite_reelle: r.quantite_reservee });
    this.erreurModal.set(null);
  }

  fermerModals(): void {
    this.reservationAAssigner.set(null);
    this.reservationAValider.set(null);
  }

  assigner(): void {
    if (this.formAssignation.invalid) { this.formAssignation.markAllAsTouched(); return; }
    const r = this.reservationAAssigner()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    this.api.assignerTransporteur(r.id, this.formAssignation.value.transporteur_id).subscribe({
      next: () => {
        this.loadingModal.set(false);
        this.fermerModals();
        this.message.set('Transporteur assigné.');
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de l\'assignation.');
        this.loadingModal.set(false);
      },
    });
  }

  valider(): void {
    if (this.formValidation.invalid) { this.formValidation.markAllAsTouched(); return; }
    const r = this.reservationAValider()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    this.api.validerMarchandise(r.id, this.formValidation.value).subscribe({
      next: () => {
        this.loadingModal.set(false);
        this.fermerModals();
        this.message.set('Marchandise enregistrée en stock. Le producteur a été notifié.');
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la validation.');
        this.loadingModal.set(false);
      },
    });
  }
}
