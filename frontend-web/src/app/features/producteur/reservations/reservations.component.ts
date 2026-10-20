import { Component, OnInit, computed, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';

@Component({
  selector: 'app-producteur-reservations',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe],
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
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
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
                  @for (p of productionsReservables(); track p.id) {
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
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
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
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr>
                <th>Numéro</th><th>Entrepôt</th><th>Produit</th>
                <th>Quantité</th><th>Date début</th><th>Statut</th><th>Actions</th>
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
                  <td><span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span></td>
                  <td>
                    @if (r.statut === 'en_attente' || (r.statut === 'confirmee' && !r.transporteur_id)) {
                      <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === r.id" (click)="annuler(r)">Annuler</button>
                    } @else if (r.statut === 'confirmee') {
                      <span class="text-muted">Transporteur assigné : contactez l'entrepôt pour annuler</span>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>
    </div>
  `,
  styles: [`
    .modal-box { --modal-largeur: 480px; }
    .text-muted { color:#6b7280; font-size:.85rem; }
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
  actionEnCours          = signal<number | null>(null);
  /** Une récolte annulée ne peut plus être réservée. */
  productionsReservables = computed(() => this.productions().filter(p => p.statut !== 'annulee'));
  form!: FormGroup;

  private cleReservation = new CleSoumission();

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
    this.cleReservation.renouveler();
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.entrepotSelectionne.set(null); }

  demanderFermeture(): void {
    if (abandonConfirme(this.form)) this.fermerModal();
  }

  annuler(r: any): void {
    const consequence = r.statut === 'confirmee'
      ? `Annuler la réservation ${r.numero_reservation}, déjà confirmée par ${r.entrepot?.nom_entrepot ?? 'l\'entrepôt'} ?\nLa place réservée sera libérée et l'entrepôt notifié. Cette annulation est définitive.`
      : `Annuler la demande ${r.numero_reservation} ?\nL'entrepôt sera notifié. Cette annulation est définitive.`;
    const motif = demanderMotif(consequence, false);
    if (motif === null) return;
    this.actionEnCours.set(r.id);
    this.erreur.set(null);
    this.api.annulerReservation(r.id, motif || undefined).subscribe({
      next: () => {
        this.actionEnCours.set(null);
        this.message.set('Réservation annulée. L\'entrepôt a été notifié.');
        this.chargerReservations();
        this.chargerEntrepots();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : réservation non annulée.' : err.error?.message ?? 'Annulation impossible.');
        this.chargerReservations();
      },
    });
  }

  reserver(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    const data = { ...this.form.value, entrepot_id: this.entrepotSelectionne()!.id };
    this.api.storeReservation(data, this.cleReservation.valeur).subscribe({
      next: () => {
        this.cleReservation.renouveler();
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
