import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { of } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';

@Component({
  selector: 'app-commandes-entrepot',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Commandes</h1>
        <span class="subtitle">{{ commandesEnAttente().length }} en attente</span>
      </div>

      @if (message()) {
        <div class="alert alert-success">{{ message() }}</div>
      }
      @if (erreur()) {
        <div class="alert alert-error">{{ erreur() }}</div>
      }

      <!-- Commandes en attente de confirmation -->
      <div class="card">
        <h2>En attente de confirmation</h2>
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (commandesEnAttente().length === 0) {
          <p class="empty">Aucune commande en attente.</p>
        } @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr>
                <th>Numéro</th><th>Produit</th><th>Quantité</th>
                <th>Prix</th><th>Date</th><th>Acheteur</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandesEnAttente(); track c.id) {
                <tr>
                  <td><code class="code-lot">{{ c.numero_commande }}</code></td>
                  <td><strong>{{ c.produit }}</strong></td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix ? (c.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>{{ c.acheteur?.utilisateur?.prenom }} {{ c.acheteur?.utilisateur?.nom }}</td>
                  <td>
                    <div class="btn-group">
                      <button class="btn-success btn-sm"
                              [disabled]="actionEnCours() === c.id"
                              (click)="ouvrirLivraison(c)">
                        Confirmer + Livraison
                      </button>
                      <button class="btn-secondary btn-sm"
                              [disabled]="actionEnCours() === c.id"
                              (click)="confirmerSeul(c.id)">
                        Confirmer seul
                      </button>
                      <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === c.id" (click)="refuser(c)">Refuser</button>
                    </div>
                  </td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>

      <!-- Modal création livraison -->
      @if (commandeSelectionnee()) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>{{ commandeSelectionnee()!.statut === 'confirmee' ? 'Créer la livraison' : 'Confirmer & créer la livraison' }}</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              Commande : <code class="code-lot">{{ commandeSelectionnee()!.numero_commande }}</code>
              — {{ commandeSelectionnee()!.produit }} / {{ commandeSelectionnee()!.quantite }} kg
            </p>

            @if (erreurModal()) {
              <div class="alert alert-error">{{ erreurModal() }}</div>
            }

            <form [formGroup]="formLivraison" (ngSubmit)="confirmerAvecLivraison()">
              <div class="form-group">
                <label>Transporteur *</label>
                <select formControlName="transporteur_id">
                  <option [ngValue]="null">— Choisir —</option>
                  @for (t of transporteurs(); track t.id) {
                    <option [ngValue]="t.id">{{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }} {{ t.type_vehicule ? '(' + t.type_vehicule + ')' : '' }}</option>
                  }
                </select>
                @if (formLivraison.get('transporteur_id')?.invalid && formLivraison.get('transporteur_id')?.touched) {
                  <span class="field-error">Choisis le transporteur qui recevra la mission.</span>
                }
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Origine *</label>
                  <input type="text" formControlName="origine" placeholder="Entrepôt, ville..." />
                </div>
                <div class="form-group">
                  <label>Destination *</label>
                  <input type="text" formControlName="destination" placeholder="Adresse livraison..." />
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Date de départ *</label>
                  <input type="datetime-local" formControlName="date_depart" />
                </div>
                <div class="form-group">
                  <label>Date livraison prévue</label>
                  <input type="datetime-local" formControlName="date_livraison" />
                </div>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-success" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Traitement...' : (commandeSelectionnee()!.statut === 'confirmee' ? 'Créer la livraison' : 'Confirmer et créer la livraison') }}
                </button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Changement du transporteur d'une livraison non démarrée -->
      @if (commandeAReassigner(); as c) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>Changer de transporteur</h2>
            <p class="note">Livraison <code class="code-lot">{{ c.livraison.numero_livraison }}</code> — elle sera retirée à
              {{ c.livraison.transporteur?.utilisateur?.prenom }} {{ c.livraison.transporteur?.utilisateur?.nom }}, qui en sera informé(e).</p>
            @if (erreurModal()) { <div class="alert alert-error">{{ erreurModal() }}</div> }
            <form [formGroup]="formReassignation" (ngSubmit)="changerTransporteur()">
              <div class="form-group">
                <label>Nouveau transporteur *</label>
                <select formControlName="transporteur_id">
                  <option [ngValue]="null">— Choisir —</option>
                  @for (t of transporteurs(); track t.id) {
                    <option [ngValue]="t.id" [disabled]="t.id === c.livraison.transporteur_id">{{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }} {{ t.type_vehicule ? '(' + t.type_vehicule + ')' : '' }}</option>
                  }
                </select>
              </div>
              <div class="form-group">
                <label>Motif (facultatif)</label>
                <textarea formControlName="motif" rows="2" maxlength="500"></textarea>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal() || formReassignation.invalid">{{ loadingModal() ? 'Envoi...' : 'Réassigner' }}</button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Commandes déjà confirmées (historique) -->
      <div class="card">
        <h2>Commandes confirmées</h2>
        @if (commandesConfirmees().length === 0) {
          <p class="empty">Aucune commande confirmée.</p>
        } @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr><th>Numéro</th><th>Produit</th><th>Quantité</th><th>Date</th><th>Livraison</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (c of commandesConfirmees(); track c.id) {
                <tr>
                  <td><code class="code-lot">{{ c.numero_commande }}</code></td>
                  <td>{{ c.produit }}</td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>
                    @if (c.livraison) {
                      <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut | libelleStatut }}</span>
                      <div class="text-muted">{{ c.livraison.transporteur?.utilisateur?.prenom }} {{ c.livraison.transporteur?.utilisateur?.nom }}</div>
                    } @else {
                      <span class="text-muted">—</span>
                    }
                  </td>
                  <td>
                    <div class="btn-group">
                      @if (!c.livraison) {
                        <button class="btn-success btn-sm" [disabled]="actionEnCours() === c.id" (click)="ouvrirLivraison(c)">Créer la livraison</button>
                        <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === c.id" (click)="annulerConfirmation(c)">Annuler la commande</button>
                      } @else if (c.livraison.statut === 'en_attente') {
                        <button class="btn-secondary btn-sm" [disabled]="actionEnCours() === c.id" (click)="ouvrirChangementTransporteur(c)">Changer de transporteur</button>
                      } @else {
                        <span class="text-muted">Livraison démarrée : plus de correction possible</span>
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
    .modal-box { --modal-largeur: 560px; }
    select, textarea { padding:.55rem .8rem; border:1px solid #e5e7eb; border-radius:6px; font-size:.9rem; width:100%; font-family:inherit; }
    .field-error { display:block; color:#dc2626; font-size:.8rem; margin-top:.3rem; }
    .note { font-size:.85rem; color:#6b7280; margin:0 0 1rem; }
    .text-muted { color:#6b7280; font-size:.85rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class CommandesEntrepotComponent implements OnInit {
  toutes              = signal<any[]>([]);
  commandesEnAttente  = signal<any[]>([]);
  commandesConfirmees = signal<any[]>([]);
  chargement          = signal(true);
  actionEnCours       = signal<number | null>(null);
  commandeSelectionnee = signal<any | null>(null);
  message             = signal<string | null>(null);
  erreur              = signal<string | null>(null);
  erreurModal         = signal<string | null>(null);
  loadingModal        = signal(false);
  formLivraison!: FormGroup;
  formReassignation!: FormGroup;
  transporteurs        = signal<any[]>([]);
  commandeAReassigner  = signal<any | null>(null);

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formLivraison = this.fb.group({
      transporteur_id: [null, Validators.required],
      origine:        ['', Validators.required],
      destination:    ['', Validators.required],
      date_depart:    ['', Validators.required],
      date_livraison: [''],
    });
    this.formReassignation = this.fb.group({ transporteur_id: [null, Validators.required], motif: [''] });
    this.charger();
    this.api.getTransporteurs().subscribe({
      next: res => this.transporteurs.set(res.transporteurs ?? []),
      error: () => {},
    });
  }

  charger(): void {
    this.chargement.set(true);
    this.api.getCommandesEntrepot().subscribe({
      next: res => {
        const all: any[] = res.commandes ?? [];
        this.commandesEnAttente.set(all.filter(c => c.statut === 'en_attente'));
        this.commandesConfirmees.set(all.filter(c => c.statut === 'confirmee'));
        this.chargement.set(false);
      },
      error: err => {
        this.chargement.set(false);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger les commandes.');
      },
    });
  }

  refuser(c: any): void {
    const motif = demanderMotif(`Refuser la commande ${c.numero_commande} ?\nElle sera annulée et l'acheteur notifié avec le motif. Le stock n'est pas modifié.`, true);
    if (motif === null) return;
    this.executer(c.id, this.api.refuserCommande(c.id, motif), 'Commande refusée. L\'acheteur a été notifié.');
  }

  annulerConfirmation(c: any): void {
    const motif = demanderMotif(`Annuler la commande confirmée ${c.numero_commande} ?\n${c.quantite} kg seront remis en stock et l'acheteur sera notifié. Cette annulation est définitive. Aucun remboursement n'est effectué automatiquement.`, true);
    if (motif === null) return;
    this.executer(c.id, this.api.annulerConfirmationCommande(c.id, motif), 'Commande annulée, quantité remise en stock.');
  }

  ouvrirChangementTransporteur(c: any): void {
    this.commandeAReassigner.set(c);
    this.formReassignation.reset({ transporteur_id: null, motif: '' });
    this.erreurModal.set(null);
  }

  changerTransporteur(): void {
    const c = this.commandeAReassigner()!;
    const { transporteur_id, motif } = this.formReassignation.value;
    this.loadingModal.set(true);
    this.erreurModal.set(null);
    this.api.changerTransporteurLivraison(c.livraison.id, transporteur_id, motif || undefined).subscribe({
      next: () => {
        this.loadingModal.set(false);
        this.fermerModal();
        this.message.set('Transporteur de la livraison modifié. Les deux transporteurs ont été notifiés.');
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.loadingModal.set(false);
        this.erreurModal.set(err.error?.message ?? 'Erreur lors du changement de transporteur.');
      },
    });
  }

  demanderFermeture(): void {
    const form = this.commandeAReassigner() ? this.formReassignation : this.formLivraison;
    if (abandonConfirme(form)) this.fermerModal();
  }

  private executer(id: number, requete: ReturnType<ApiService['getCommandesEntrepot']>, succes: string): void {
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

  confirmerSeul(id: number): void {
    if (!confirm('Confirmer cette commande sans créer de livraison ?')) return;
    this.actionEnCours.set(id);
    this.erreur.set(null);
    this.api.confirmerCommande(id).subscribe({
      next: () => {
        this.message.set('Commande confirmée. Le stock a été mis à jour.');
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

  ouvrirLivraison(c: any): void {
    this.commandeSelectionnee.set(c);
    this.formLivraison.reset();
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.commandeSelectionnee.set(null); this.commandeAReassigner.set(null); }

  confirmerAvecLivraison(): void {
    if (this.formLivraison.invalid) { this.formLivraison.markAllAsTouched(); return; }
    const c = this.commandeSelectionnee()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    const confirmation = c.statut === 'confirmee' ? of(null) : this.api.confirmerCommande(c.id);
    confirmation.subscribe({
      next: () => {
        const livData = { ...this.formLivraison.value, commande_id: c.id };
        this.api.createLivraison(livData).subscribe({
          next: () => {
            this.loadingModal.set(false);
            this.fermerModal();
            this.message.set('Commande confirmée et livraison créée avec succès.');
            this.charger();
            setTimeout(() => this.message.set(null), 4000);
          },
          error: err => {
            this.erreurModal.set(err.error?.message ?? 'Commande confirmée mais erreur à la création de la livraison.');
            this.loadingModal.set(false);
          },
        });
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la confirmation.');
        this.loadingModal.set(false);
      },
    });
  }
}
