import { RouterLink } from '@angular/router';
import { Component, OnInit, computed, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe, NgTemplateOutlet } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';
import { ActionDepot, ETAPES_NORMALES, ETATS_MARCHANDISE, LIBELLE_ACTION, LIBELLE_MODE, peut } from '../../../shared/depot/depot';

/** Statuts du transport en cours (après acceptation, avant réception). */
const EN_ACHEMINEMENT = ['prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'];

@Component({
  selector: 'app-entrepot-reservations',
  standalone: true,
  imports: [RouterLink, LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe, NgTemplateOutlet],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Dépôts de marchandise</h1>
        <span class="subtitle">Demandes des producteurs, transport vers l'entrepôt et réception</span>
      </div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      @if (chargement()) { <p class="loading">Chargement...</p> }

      <!-- 1. Réceptions à contrôler : priorité, la marchandise attend à l'entrepôt -->
      <div class="card">
        <h2>Réceptions à contrôler <span class="compteur">{{ arrivees().length }}</span></h2>
        @if (arrivees().length === 0) { <p class="empty">Aucune marchandise en attente de contrôle.</p> }
        @else { <ng-container *ngTemplateOutlet="tableau; context: { liste: arrivees() }" /> }
      </div>

      <!-- 2. Demandes à examiner -->
      <div class="card">
        <h2>Demandes à examiner <span class="compteur">{{ enAttente().length }}</span></h2>
        @if (enAttente().length === 0) { <p class="empty">Aucune demande en attente.</p> }
        @else { <ng-container *ngTemplateOutlet="tableau; context: { liste: enAttente() }" /> }
      </div>

      <!-- 3. Transporteur à affecter ou à faire accepter -->
      <div class="card">
        <h2>Transport à organiser <span class="compteur">{{ aOrganiser().length }}</span></h2>
        @if (aOrganiser().length === 0) { <p class="empty">Aucun transport à organiser.</p> }
        @else { <ng-container *ngTemplateOutlet="tableau; context: { liste: aOrganiser() }" /> }
      </div>

      <!-- 4. En route ou en collecte -->
      <div class="card">
        <h2>En cours d'acheminement <span class="compteur">{{ enCours().length }}</span></h2>
        @if (enCours().length === 0) { <p class="empty">Aucune marchandise en route.</p> }
        @else { <ng-container *ngTemplateOutlet="tableau; context: { liste: enCours() }" /> }
      </div>

      @if (terminees().length > 0) {
        <div class="card">
          <h2>Terminées</h2>
          <ul class="liste-simple">
            @for (r of terminees(); track r.id) {
              <li><code class="code-lot">{{ r.numero_reservation }}</code> {{ r.produit }} · {{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}
                <span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span>
                @if (r.statut === 'enregistree' && r.quantite_recue !== null) { — {{ r.quantite_recue | number }} kg reçus sur {{ r.quantite_reservee | number }} }
              </li>
            }
          </ul>
        </div>
      }

      @if (historique().length > 0) {
        <div class="card">
          <h2>Dernières corrections</h2>
          <ul class="liste-simple">
            @for (h of historique(); track h.id) {
              <li>{{ h.created_at | date:'dd/MM/yyyy HH:mm' }} — <code class="code-lot">{{ h.numero }}</code>
                {{ libelleCorrection(h.action) }} par {{ h.utilisateur?.prenom }} {{ h.utilisateur?.nom }}@if (h.motif) { · « {{ h.motif }} » }</li>
            }
          </ul>
        </div>
      }

      <ng-template #tableau let-liste="liste">
        <div class="table-scroll"><table class="table" appTableauResponsive>
          <thead>
            <tr><th>Numéro</th><th>Producteur</th><th>Lot</th><th>Quantité</th><th>Transport</th><th>Étape</th><th>Actions</th></tr>
          </thead>
          <tbody>
            @for (r of liste; track r.id) {
              <tr>
                <td><code class="code-lot">{{ r.numero_reservation }}</code></td>
                <td>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}
                  @if (r.producteur?.utilisateur?.telephone) { <br><a class="tel" [href]="'tel:' + r.producteur.utilisateur.telephone">{{ r.producteur.utilisateur.telephone }}</a> }</td>
                <td>{{ r.produit }}</td>
                <td>{{ r.quantite_reservee | number }} kg</td>
                <td>{{ mode(r) }}
                  @if (r.transporteur) { <br><small>{{ r.transporteur.utilisateur?.prenom }} {{ r.transporteur.utilisateur?.nom }}</small> }</td>
                <td><span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span>
                  @if (r.prochaine_etape) { <br><small class="text-muted">{{ r.prochaine_etape.libelle }}</small> }</td>
                <td>
                  <div class="btn-group">
                    @if (r.statut === 'en_route') {
                      <a class="btn-secondary btn-sm" [routerLink]="['/suivi', 'reservation', r.id]">📍 Suivre</a>
                    }
                    @for (a of actionsGestionnaire(r); track a) {
                      <button [class]="classeBouton(a)" [disabled]="actionEnCours() === r.id" (click)="agir(r, a)">{{ libelle(a, r) }}</button>
                    }
                  </div>
                </td>
              </tr>
            }
          </tbody>
        </table></div>
      </ng-template>

      <!-- Affectation / réaffectation du transporteur -->
      @if (reservationAAssigner(); as r) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>{{ r.transporteur_id ? 'Changer de transporteur' : 'Affecter un transporteur' }}</h2>
            <p class="aide">Réservation <code class="code-lot">{{ r.numero_reservation }}</code> — {{ r.produit }}, {{ r.quantite_reservee | number }} kg.
              Le transporteur devra accepter la mission.</p>
            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }
            <form [formGroup]="formAssignation" (ngSubmit)="assigner()">
              <div class="form-group">
                <label>Transporteur * (les moins occupés en premier)</label>
                <select formControlName="transporteur_id">
                  <option [ngValue]="null">— Choisir —</option>
                  @for (t of transporteurs(); track t.id) {
                    <option [ngValue]="t.id" [disabled]="t.id === r.transporteur_id">
                      {{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }}
                      {{ t.type_vehicule ? '(' + t.type_vehicule + ')' : '' }}
                      — {{ t.missions_actives ? t.missions_actives + ' mission(s) en cours' : 'disponible' }}
                    </option>
                  }
                </select>
                @if (formAssignation.get('transporteur_id')?.invalid && formAssignation.get('transporteur_id')?.touched) {
                  <span class="field-error">Choisis un transporteur.</span>
                }
              </div>
              @if (r.transporteur_id) {
                <p class="aide">La mission sera retirée à {{ r.transporteur?.utilisateur?.prenom }} {{ r.transporteur?.utilisateur?.nom }}, qui en sera informé(e).</p>
                <div class="form-group">
                  <label>Motif du changement (facultatif)</label>
                  <textarea formControlName="motif" rows="2" maxlength="500"></textarea>
                </div>
              }
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Envoi...' : (r.transporteur_id ? 'Réaffecter' : 'Affecter') }}
                </button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Contrôle de la réception -->
      @if (reservationAValider(); as r) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>Contrôler la réception</h2>
            <p class="aide">Réservation <code class="code-lot">{{ r.numero_reservation }}</code> — {{ r.produit }},
              <strong>{{ r.quantite_reservee | number }} kg prévus</strong>.</p>
            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }
            <form [formGroup]="formValidation" (ngSubmit)="valider()">
              <div class="form-group">
                <label>Code du lot inscrit sur la marchandise *</label>
                <input type="text" formControlName="code_lot" placeholder="LOT-2026-XXXXXX" autocomplete="off" />
                <span class="aide-champ">Recopiez le code de l'étiquette : il est comparé au lot attendu.</span>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Quantité reçue (kg) *</label>
                  <input type="number" formControlName="quantite_reelle" min="0.01" step="any" />
                </div>
                <div class="form-group">
                  <label>État de la marchandise *</label>
                  <select formControlName="etat_marchandise">
                    <option [ngValue]="null">— Choisir —</option>
                    @for (e of etats; track e.code) { <option [ngValue]="e.code">{{ e.libelle }}</option> }
                  </select>
                </div>
              </div>
              @if (ecart(r)) {
                <div class="form-group">
                  <label>Raison de l'écart * ({{ formValidation.value.quantite_reelle | number }} kg au lieu de {{ r.quantite_reservee | number }})</label>
                  <input type="text" formControlName="motif" maxlength="500" placeholder="Ex. : sacs manquants, pesée différente…" />
                </div>
              }
              <div class="form-group">
                <label>Observation (facultatif)</label>
                <textarea formControlName="observation" rows="2" placeholder="Qualité constatée…"></textarea>
              </div>
              @if (formValidation.invalid && formValidation.touched) {
                <span class="field-error">Code du lot, quantité et état sont obligatoires.</span>
              }
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Envoi...' : 'Valider et enregistrer en stock' }}
                </button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .modal-box { --modal-largeur: 520px; }
    select, textarea {
      padding:.55rem .8rem;border:1px solid #e5e7eb;border-radius:6px;
      font-size:.9rem;width:100%;font-family:inherit;
    }
    .aide { font-size:.9rem; color:#6b7280; margin:0 0 1rem; }
    .aide-champ { display:block; font-size:.8rem; color:#6b7280; margin-top:.25rem; }
    .liste-simple { margin:0; padding-left:1.1rem; display:grid; gap:.4rem; font-size:.9rem; }
    .compteur { font-size:.8rem; background:#f3f4f6; border-radius:10px; padding:.1rem .5rem; margin-left:.3rem; }
    .tel { font-size:.85rem; }
    .field-error { display:block;color:#dc2626;font-size:.8rem;margin-top:.3rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class EntrepotReservationsComponent implements OnInit {
  toutes               = signal<any[]>([]);
  transporteurs        = signal<any[]>([]);
  chargement           = signal(true);
  actionEnCours        = signal<number | null>(null);
  reservationAAssigner = signal<any | null>(null);
  reservationAValider  = signal<any | null>(null);
  loadingModal         = signal(false);
  message              = signal<string | null>(null);
  erreur               = signal<string | null>(null);
  erreurModal          = signal<string | null>(null);
  readonly etats       = ETATS_MARCHANDISE;
  formAssignation!: FormGroup;
  formValidation!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formAssignation = this.fb.group({ transporteur_id: [null, Validators.required], motif: [''] });
    this.formValidation = this.fb.group({
      code_lot:         ['', Validators.required],
      quantite_reelle:  [null, [Validators.required, Validators.min(0.01)]],
      etat_marchandise: [null, Validators.required],
      motif:            [''],
      observation:      [''],
    });

    this.charger();
    this.chargerTransporteurs();
  }

  arrivees   = computed(() => this.toutes().filter(r => r.statut === 'arrivee_entrepot'));
  enAttente  = computed(() => this.toutes().filter(r => r.statut === 'en_attente'));
  aOrganiser = computed(() => this.toutes().filter(r => r.statut === 'confirmee'));
  enCours    = computed(() => this.toutes().filter(r => EN_ACHEMINEMENT.includes(r.statut)));
  terminees  = computed(() => this.toutes().filter(r => ['enregistree', 'refusee', 'annulee', 'reception_refusee'].includes(r.statut)).slice(0, 15));
  /** Corrections et annulations seulement : les étapes normales sont visibles dans la colonne « Étape ». */
  historique = computed(() => this.toutes()
    .flatMap(r => (r.historique ?? []).map((h: any) => ({ ...h, numero: r.numero_reservation })))
    .filter(h => !ETAPES_NORMALES.includes(h.action))
    .sort((a, b) => b.id - a.id)
    .slice(0, 10));

  /** Actions du gestionnaire, dans l'ordre de priorité, renvoyées par le serveur. */
  actionsGestionnaire(r: any): ActionDepot[] {
    const ordre: ActionDepot[] = ['receptionner', 'refuser_reception', 'confirmer', 'refuser', 'assigner', 'retirer_transporteur', 'remettre_en_attente'];
    return ordre.filter(a => peut(r, a));
  }

  libelle(a: ActionDepot, r: any): string {
    return a === 'assigner' && r.transporteur_id ? 'Changer de transporteur' : LIBELLE_ACTION[a];
  }

  classeBouton(a: ActionDepot): string {
    if (a === 'receptionner' || a === 'confirmer') return 'btn-success btn-sm';
    if (a === 'assigner') return 'btn-secondary btn-sm';
    return 'btn-ghost btn-sm';
  }

  mode(r: any): string { return LIBELLE_MODE[r.mode_transport] ?? r.mode_transport; }

  ecart(r: any): boolean {
    const q = Number(this.formValidation.value.quantite_reelle);
    return q > 0 && Math.abs(q - r.quantite_reservee) > 1e-9;
  }

  libelleCorrection(action: string): string {
    return ({
      assignation_transporteur:   'transporteur affecté',
      reassignation_transporteur: 'transporteur changé',
      retrait_transporteur:       'mission retirée au transporteur',
      refus_mission:              'mission refusée par le transporteur',
      refus:                      'demande refusée',
      retour_en_attente:          'acceptation annulée',
      annulation:                 'annulée par le producteur',
      reception_partielle:        'réception avec écart',
      refus_reception:            'marchandise refusée',
    } as Record<string, string>)[action] ?? action;
  }

  charger(): void {
    this.api.getReservations().subscribe({
      next: res => { this.toutes.set(res.reservations ?? []); this.chargement.set(false); },
      error: err => {
        this.chargement.set(false);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger les réservations.');
      },
    });
  }

  chargerTransporteurs(): void {
    this.api.getTransporteurs().subscribe({ next: res => this.transporteurs.set(res.transporteurs ?? []), error: () => {} });
  }

  agir(r: any, a: ActionDepot): void {
    switch (a) {
      case 'confirmer':
        return this.executer(r.id, this.api.confirmerReservation(r.id), 'Dépôt accepté. Le producteur a été notifié.');
      case 'refuser': {
        const motif = demanderMotif(`Refuser la demande ${r.numero_reservation} ?\nLe producteur sera notifié avec le motif.`, true);
        if (motif === null) return;
        return this.executer(r.id, this.api.refuserReservation(r.id, motif), 'Demande refusée. Le producteur a été notifié.');
      }
      case 'remettre_en_attente': {
        const motif = demanderMotif(`Annuler l'acceptation de ${r.numero_reservation} ?\nLa demande repassera « en attente » et le producteur sera notifié.`, false);
        if (motif === null) return;
        return this.executer(r.id, this.api.remettreReservationEnAttente(r.id, motif || undefined), 'Acceptation annulée : la demande est de nouveau en attente.');
      }
      case 'assigner':
        this.reservationAAssigner.set(r);
        this.formAssignation.reset();
        this.erreurModal.set(null);
        this.chargerTransporteurs();
        return;
      case 'retirer_transporteur': {
        const nom = `${r.transporteur?.utilisateur?.prenom ?? ''} ${r.transporteur?.utilisateur?.nom ?? ''}`.trim();
        const motif = demanderMotif(`Retirer la mission ${r.numero_reservation} à ${nom} ?\nIl sera prévenu et la mission disparaîtra de son espace.`, false);
        if (motif === null) return;
        return this.executer(r.id, this.api.retirerTransporteur(r.id, motif || undefined), 'Mission retirée au transporteur.');
      }
      case 'receptionner':
        this.reservationAValider.set(r);
        this.formValidation.reset({ code_lot: '', quantite_reelle: r.quantite_reservee, etat_marchandise: null, motif: '', observation: '' });
        this.erreurModal.set(null);
        return;
      case 'refuser_reception': {
        const motif = demanderMotif(`Refuser la marchandise de ${r.numero_reservation} ?\nAucun stock ne sera créé : la marchandise doit être reprise. Le producteur sera prévenu.`, true);
        if (motif === null) return;
        return this.executer(r.id, this.api.refuserReception(r.id, motif), 'Marchandise refusée. Le producteur a été prévenu.');
      }
    }
  }

  demanderFermeture(): void {
    const form = this.reservationAAssigner() ? this.formAssignation : this.formValidation;
    if (abandonConfirme(form)) this.fermerModals();
  }

  fermerModals(): void {
    this.reservationAAssigner.set(null);
    this.reservationAValider.set(null);
  }

  private executer(id: number, requete: ReturnType<ApiService['getReservations']>, succes: string): void {
    this.actionEnCours.set(id);
    this.erreur.set(null);
    requete.subscribe({
      next: () => {
        this.actionEnCours.set(null);
        this.message.set(succes);
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : action non enregistrée.' : err.error?.message ?? 'Action impossible.');
        this.charger();
      },
    });
  }

  assigner(): void {
    if (this.formAssignation.invalid) { this.formAssignation.markAllAsTouched(); return; }
    const r = this.reservationAAssigner()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    const { transporteur_id, motif } = this.formAssignation.value;
    this.api.assignerTransporteur(r.id, transporteur_id, motif || undefined).subscribe({
      next: res => {
        this.loadingModal.set(false);
        this.fermerModals();
        this.message.set(res?.message ?? 'Transporteur affecté.');
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de l\'affectation.');
        this.loadingModal.set(false);
      },
    });
  }

  valider(): void {
    const r = this.reservationAValider()!;
    if (this.formValidation.invalid) { this.formValidation.markAllAsTouched(); return; }
    if (this.ecart(r) && !String(this.formValidation.value.motif ?? '').trim()) {
      this.erreurModal.set('La quantité reçue diffère de la quantité prévue : indiquez la raison de l\'écart.');
      return;
    }
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    const { code_lot, quantite_reelle, etat_marchandise, motif, observation } = this.formValidation.value;
    this.api.validerMarchandise(r.id, {
      code_lot: String(code_lot).trim(), quantite_reelle, etat_marchandise,
      motif: this.ecart(r) ? motif : undefined, observation: observation || undefined,
    }).subscribe({
      next: res => {
        this.loadingModal.set(false);
        this.fermerModals();
        this.message.set(res?.message ?? 'Marchandise enregistrée en stock. Le producteur a été notifié.');
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
