import { Component, OnInit, computed, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';
import { CONFIRMATION_ETAPE, ETAPE_DE_L_ACTION, LIBELLE_ACTION, LIBELLE_MODE, ActionDepot, peut } from '../../../shared/depot/depot';

@Component({
  selector: 'app-producteur-reservations',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Déposer ma récolte</h1>
        <span class="subtitle">Choisissez un entrepôt qui a de la place, puis qui transporte la marchandise</span>
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
                <label>Récolte à déposer *</label>
                <select formControlName="production_id" (change)="recolteChoisie()">
                  <option [ngValue]="null">— Choisir une récolte —</option>
                  @for (p of productionsReservables(); track p.id) {
                    <option [ngValue]="p.id">{{ p.code_tracabilite }} — {{ p.type_culture }}</option>
                  }
                </select>
                @if (productionsReservables().length === 0) {
                  <span class="field-error">Enregistre d'abord ta récolte (page Mes productions) : chaque dépôt correspond à un lot.</span>
                } @else if (form.get('production_id')?.invalid && form.get('production_id')?.touched) {
                  <span class="field-error">Choisis la récolte que tu veux déposer.</span>
                }
              </div>
              <div class="form-group">
                <label>Qui transporte la marchandise ? *</label>
                <div class="choix-mode">
                  <label class="choix"><input type="radio" formControlName="mode_transport" value="transporteur" />
                    <span><strong>Un transporteur vient la chercher</strong><br><small>L'entrepôt affecte un transporteur ; vous suivez son arrivée.</small></span></label>
                  <label class="choix"><input type="radio" formControlName="mode_transport" value="producteur" />
                    <span><strong>Je la livre moi-même</strong><br><small>Vous indiquez votre départ puis votre arrivée à l'entrepôt.</small></span></label>
                </div>
                @if (form.get('mode_transport')?.invalid && form.get('mode_transport')?.touched) {
                  <span class="field-error">Choisis qui transporte la marchandise.</span>
                }
              </div>
              <div class="form-group">
                <label>Produit</label>
                <input type="text" formControlName="produit" placeholder="Repris de la récolte" />
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
                <th>Quantité</th><th>Transport</th><th>Étape</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (r of reservations(); track r.id) {
                <tr>
                  <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                  <td>{{ r.entrepot?.nom_entrepot }}</td>
                  <td>{{ r.produit }}</td>
                  <td>{{ r.quantite_reservee | number }} kg
                    @if (r.statut === 'enregistree' && r.quantite_recue !== null) { <br><small>{{ r.quantite_recue | number }} kg reçus</small> }</td>
                  <td>{{ mode(r) }}
                    @if (r.transporteur) { <br><small>{{ r.transporteur.utilisateur?.prenom }} {{ r.transporteur.utilisateur?.nom }}
                      @if (r.transporteur.utilisateur?.telephone) { · <a [href]="'tel:' + r.transporteur.utilisateur.telephone">{{ r.transporteur.utilisateur.telephone }}</a> }</small> }</td>
                  <td><span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span>
                    @if (r.prochaine_etape) { <br><small class="text-muted">{{ r.prochaine_etape.libelle }}</small> }</td>
                  <td>
                    <div class="btn-group">
                      @if (peut(r, 'partir')) {
                        <button class="btn-primary btn-sm" [disabled]="actionEnCours() === r.id" (click)="etape(r, 'partir')">{{ libelle('partir') }}</button>
                      }
                      @if (peut(r, 'arriver')) {
                        <button class="btn-success btn-sm" [disabled]="actionEnCours() === r.id" (click)="etape(r, 'arriver')">{{ libelle('arriver') }}</button>
                      }
                      @if (peut(r, 'annuler')) {
                        <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === r.id" (click)="annuler(r)">{{ libelle('annuler') }}</button>
                      }
                    </div>
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
    .choix-mode { display:grid; gap:.5rem; }
    .choix { display:flex; gap:.6rem; align-items:flex-start; padding:.6rem .75rem; border:1px solid #e5e7eb; border-radius:8px; cursor:pointer; font-weight:normal; }
    .choix input { margin-top:.2rem; }
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

  readonly peut = peut;

  libelle(a: ActionDepot): string { return LIBELLE_ACTION[a]; }

  mode(r: any): string { return LIBELLE_MODE[r.mode_transport] ?? r.mode_transport; }

  /** Producteur qui livre lui-même : départ puis arrivée, avec confirmation. */
  etape(r: any, a: ActionDepot): void {
    if (!confirm(CONFIRMATION_ETAPE[a] ?? 'Confirmer ?')) return;
    this.actionEnCours.set(r.id);
    this.erreur.set(null);
    this.api.etapeReservation(r.id, ETAPE_DE_L_ACTION[a]!).subscribe({
      next: res => {
        this.actionEnCours.set(null);
        this.message.set(res?.message ?? 'Étape enregistrée.');
        this.chargerReservations();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : étape non enregistrée, réessayez.' : err.error?.message ?? 'Étape impossible.');
        this.chargerReservations();
      },
    });
  }

  /** Reprend le produit et la quantité de la récolte choisie : pas de ressaisie (modifiables avant envoi). */
  recolteChoisie(): void {
    const p = this.productions().find(x => x.id === this.form.value.production_id);
    if (!p) return;
    this.form.patchValue({ produit: p.type_culture, quantite_reservee: p.quantite_reelle ?? p.quantite_estimee });
  }
  form!: FormGroup;

  private cleReservation = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      production_id:     [null, Validators.required],
      produit:           [''],
      mode_transport:    [null, Validators.required],
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
    const consequence = r.statut === 'en_attente'
      ? `Annuler la demande ${r.numero_reservation} ?\nL'entrepôt sera notifié. Cette annulation est définitive.`
      : `Annuler le dépôt ${r.numero_reservation}, déjà accepté par ${r.entrepot?.nom_entrepot ?? 'l\'entrepôt'} ?\nLa place sera libérée et l'entrepôt${r.transporteur_id ? ' ainsi que le transporteur' : ''} prévenu. Cette annulation est définitive.`;
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
