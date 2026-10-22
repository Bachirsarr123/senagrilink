import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { abandonConfirme } from '../../../shared/confirmation/confirmation';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';

/** Raisons d'ajustement acceptées par l'API (décision Q14). */
export const MOTIFS_AJUSTEMENT: { code: string; libelle: string }[] = [
  { code: 'perte',                 libelle: 'Perte' },
  { code: 'avarie',                libelle: 'Avarie (produit abîmé)' },
  { code: 'retrait_producteur',    libelle: 'Retrait par le producteur' },
  { code: 'correction_inventaire', libelle: 'Correction d\'inventaire' },
];

/** Libellé d'une ligne du journal des mouvements. */
export function libelleMouvement(m: { type: string; motif_type?: string | null }): string {
  switch (m.type) {
    case 'ouverture':   return 'Stock existant repris';
    case 'entree':      return 'Entrée (réception)';
    case 'reservation': return 'Réservé pour une commande';
    case 'liberation':  return 'Réservation libérée';
    case 'sortie':      return 'Sortie (départ de livraison)';
    case 'ajustement':  return 'Ajustement : ' + (MOTIFS_AJUSTEMENT.find(x => x.code === m.motif_type)?.libelle ?? m.motif_type ?? '');
    default:            return m.type;
  }
}

@Component({
  selector: 'app-stocks',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Gestion des stocks</h1>
      </div>

      <p class="aide">
        Les entrées se font à la réception d'une marchandise (page Réservations), les sorties au départ d'une livraison.
        <strong>Réservé</strong> : promis à des commandes confirmées. <strong>Disponible</strong> : ce que les acheteurs peuvent encore commander.
      </p>

      @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }

      <div class="card">
        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (erreurChargement()) {
          <div class="alert alert-error">{{ erreurChargement() }}</div>
          <button class="btn-secondary" (click)="chargerStocks()">Réessayer</button>
        }
        @else if (stocks().length === 0) { <p class="empty">Aucun stock enregistré.</p> }
        @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr>
                <th>Produit</th><th>Lot</th><th>En entrepôt</th><th>Réservé</th><th>Disponible</th>
                <th>Seuil alerte</th><th>Statut</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (s of stocks(); track s.id) {
                <tr [class.row-alert]="enAlerte(s)">
                  <td><strong>{{ s.produit }}</strong></td>
                  <td>@if (s.production?.code_tracabilite) { <code class="code-lot">{{ s.production.code_tracabilite }}</code> } @else { — }</td>
                  <td>{{ s.quantite | number }} kg</td>
                  <td>{{ s.quantite_reservee | number }} kg</td>
                  <td><strong>{{ s.quantite_disponible | number }} kg</strong></td>
                  <td>{{ s.seuil_alerte !== null && s.seuil_alerte !== undefined ? (s.seuil_alerte | number) + ' kg' : '—' }}</td>
                  <td><span class="badge badge-{{ s.statut }}">{{ s.statut | libelleStatut }}</span></td>
                  <td class="actions">
                    <button class="btn-sm btn-secondary" (click)="ouvrirAjustement(s)">Ajuster</button>
                    <button class="btn-sm btn-ghost" (click)="modifierSeuil(s)">Seuil</button>
                    <button class="btn-sm btn-ghost" (click)="ouvrirJournal(s)">Journal</button>
                  </td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>

      <!-- Ajustement motivé -->
      @if (stockAjuste(); as s) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>Ajuster le stock</h2>
            <p class="aide">{{ s.produit }} — disponible : <strong>{{ s.quantite_disponible | number }} kg</strong>
              (une quantité réservée pour une commande ne peut pas être retirée).</p>
            @if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
            <form [formGroup]="formAjustement" (ngSubmit)="enregistrerAjustement()">
              <div class="form-row">
                <div class="form-group">
                  <label>Raison *</label>
                  <select formControlName="motif_type" (change)="raisonChangee()">
                    <option [ngValue]="null">— Choisir —</option>
                    @for (m of motifs; track m.code) { <option [ngValue]="m.code">{{ m.libelle }}</option> }
                  </select>
                </div>
                <div class="form-group">
                  <label>Sens *</label>
                  <select formControlName="sens">
                    <option value="retrait">Retirer du stock</option>
                    @if (formAjustement.value.motif_type === 'correction_inventaire') { <option value="ajout">Ajouter au stock</option> }
                  </select>
                </div>
                <div class="form-group">
                  <label>Quantité (kg) *</label>
                  <input type="number" formControlName="quantite" min="0.01" step="any" />
                </div>
              </div>
              <div class="form-group">
                <label>Explication *</label>
                <input type="text" formControlName="motif" maxlength="500" placeholder="Ex. : sacs mouillés après la pluie" />
              </div>
              @if (formAjustement.invalid && formAjustement.touched) {
                <span class="field-error">Indique la raison, une quantité supérieure à zéro et une explication.</span>
              }
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loading()">{{ loading() ? 'Enregistrement...' : "Enregistrer l'ajustement" }}</button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Journal des mouvements -->
      @if (stockJournal(); as s) {
        <div class="modal-overlay" (click)="stockJournal.set(null)">
          <div class="modal-box modal-large" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>Journal — {{ s.produit }}</h2>
            @if (chargementJournal()) { <p class="loading">Chargement...</p> }
            @else if (erreurJournal()) { <div class="alert alert-error">{{ erreurJournal() }}</div> }
            @else if (mouvements().length === 0) { <p class="empty">Aucun mouvement.</p> }
            @else {
              <div class="table-scroll"><table class="table" appTableauResponsive>
                <thead><tr><th>Date</th><th>Opération</th><th>En entrepôt</th><th>Réservé</th><th>Par</th><th>Motif</th></tr></thead>
                <tbody>
                  @for (m of mouvements(); track m.id) {
                    <tr>
                      <td>{{ m.created_at | date:'dd/MM/yyyy HH:mm' }}</td>
                      <td>{{ libelle(m) }}</td>
                      <td>{{ signe(m.variation_physique) }} → {{ m.physique_apres | number }} kg</td>
                      <td>{{ signe(m.variation_reservee) }} → {{ m.reservee_apres | number }} kg</td>
                      <td>{{ m.utilisateur ? m.utilisateur.prenom + ' ' + m.utilisateur.nom : '—' }}</td>
                      <td>{{ m.motif || '—' }}</td>
                    </tr>
                  }
                </tbody>
              </table></div>
            }
            <div class="btn-group"><button type="button" class="btn-ghost" (click)="stockJournal.set(null)">Fermer</button></div>
          </div>
        </div>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
  styles: [`
    .aide { color: #6b7280; font-size: .9rem; margin-bottom: 1rem; }
    .actions { display: flex; gap: .35rem; flex-wrap: wrap; }
    .modal-large { --modal-largeur: 860px; }
  `],
})
export class StocksComponent implements OnInit {
  stocks            = signal<any[]>([]);
  chargement        = signal(true);
  erreurChargement  = signal<string | null>(null);
  loading           = signal(false);
  erreur            = signal<string | null>(null);
  succes            = signal<string | null>(null);
  stockAjuste       = signal<any | null>(null);
  stockJournal      = signal<any | null>(null);
  mouvements        = signal<any[]>([]);
  chargementJournal = signal(false);
  erreurJournal     = signal<string | null>(null);
  readonly motifs   = MOTIFS_AJUSTEMENT;
  formAjustement: FormGroup;

  private cleAjustement = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.formAjustement = this.fb.group({
      motif_type: [null, Validators.required],
      sens:       ['retrait', Validators.required],
      quantite:   [null, [Validators.required, Validators.min(0.01)]],
      motif:      ['', [Validators.required, Validators.maxLength(500)]],
    });
  }

  ngOnInit(): void {
    this.chargerStocks();
  }

  chargerStocks(): void {
    this.erreurChargement.set(null);
    this.api.getStocks().subscribe({
      next: res => { this.stocks.set(res.stocks); this.chargement.set(false); },
      error: err => {
        this.erreurChargement.set(err.error?.message ?? 'Impossible de charger les stocks. Vérifie ta connexion.');
        this.chargement.set(false);
      },
    });
  }

  enAlerte(s: any): boolean {
    return s.seuil_alerte !== null && s.seuil_alerte !== undefined && s.quantite_disponible <= s.seuil_alerte;
  }

  libelle(m: any): string { return libelleMouvement(m); }

  signe(v: number): string { return v > 0 ? `+${v}` : `${v}`; }

  /** Une clé d'idempotence par ouverture de formulaire : chaque ouverture est une nouvelle saisie. */
  ouvrirAjustement(s: any): void {
    this.formAjustement.reset({ motif_type: null, sens: 'retrait', quantite: null, motif: '' });
    this.erreur.set(null);
    this.cleAjustement.renouveler();
    this.stockAjuste.set(s);
  }

  raisonChangee(): void {
    if (this.formAjustement.value.motif_type !== 'correction_inventaire') this.formAjustement.patchValue({ sens: 'retrait' });
  }

  demanderFermeture(): void {
    if (abandonConfirme(this.formAjustement)) this.stockAjuste.set(null);
  }

  enregistrerAjustement(): void {
    const s = this.stockAjuste();
    if (!s) return;
    if (this.formAjustement.invalid) { this.formAjustement.markAllAsTouched(); return; }
    this.loading.set(true); this.erreur.set(null);
    this.api.ajusterStock(s.id, this.formAjustement.value, this.cleAjustement.valeur).subscribe({
      next: () => {
        this.loading.set(false);
        this.stockAjuste.set(null);
        this.succes.set(`Ajustement enregistré pour ${s.produit}.`);
        this.chargerStocks();
      },
      error: err => { this.erreur.set(err.error?.message ?? 'Ajustement impossible. Réessaie.'); this.loading.set(false); },
    });
  }

  modifierSeuil(s: any): void {
    const saisie = prompt(`Seuil d'alerte pour ${s.produit} (kg). Laisser vide pour ne plus être alerté.`, s.seuil_alerte ?? '');
    if (saisie === null) return;
    const texte = saisie.trim().replace(',', '.');
    const seuil = texte === '' ? null : Number(texte);
    if (seuil !== null && (!Number.isFinite(seuil) || seuil < 0)) { alert('Indique un nombre positif, ou laisse vide.'); return; }
    this.api.modifierSeuilStock(s.id, seuil).subscribe({
      next: () => { this.succes.set(`Seuil d'alerte mis à jour pour ${s.produit}.`); this.chargerStocks(); },
      error: err => alert(err.error?.message ?? 'Modification impossible. Réessaie.'),
    });
  }

  ouvrirJournal(s: any): void {
    this.stockJournal.set(s);
    this.mouvements.set([]);
    this.erreurJournal.set(null);
    this.chargementJournal.set(true);
    this.api.getMouvementsStock(s.id).subscribe({
      next: res => { this.mouvements.set(res.mouvements); this.chargementJournal.set(false); },
      error: err => { this.erreurJournal.set(err.error?.message ?? 'Impossible de charger le journal.'); this.chargementJournal.set(false); },
    });
  }
}
