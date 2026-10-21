import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { IncidentFormComponent } from '../../../shared/incident/incident-form';
import { demanderMotif } from '../../../shared/confirmation/confirmation';
import { ANOMALIES, ActionCommande, DECISION_PRISE, LIBELLE_ACTION_COMMANDE, libelleIncident, peutFaire } from '../../../shared/livraison/livraison';

@Component({
  selector: 'app-mes-commandes',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe, RouterLink, IncidentFormComponent],
  template: `
    <div class="page">
      <div class="page-header"><h1>Mes commandes</h1></div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <div class="card">
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (erreurChargement()) {
          <div class="alert alert-error">{{ erreurChargement() }}</div>
          <button class="btn-secondary" (click)="charger()">Réessayer</button>
        } @else if (commandes().length === 0) {
          <p class="empty">Aucune commande passée.</p>
        } @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr>
                <th>Numéro</th><th>Produit</th><th>Qté</th>
                <th>Prix / kg</th><th>Montant</th><th>Date</th><th>Statut</th><th>Livraison</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandes(); track c.id) {
                <tr>
                  <td><a [routerLink]="['/commandes', c.id]" title="Ouvrir la fiche"><code class="code-lot">{{ c.numero_commande }}</code></a></td>
                  <td>{{ c.produit }}</td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix_unitaire ? (c.prix_unitaire | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.prix ? (c.prix | number:'1.0-2') + ' FCFA' : '—' }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>
                    <span class="badge badge-{{ c.statut }}">{{ c.statut | libelleStatut }}</span>
                    @if (c.alerte_livraison) {
                      <span class="badge badge-probleme" title="Incident sur la livraison">⚠️ Incident</span>
                    }
                    @if (c.prochaine_etape) { <div class="etape">{{ c.prochaine_etape.libelle }}</div> }
                    @if (c.panier) { <div class="etape">Panier {{ c.panier }}</div> }
                  </td>
                  <td>
                    @if (c.livraison) {
                      <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut | libelleStatut }}</span>
                      @if (c.livraison.transporteur?.utilisateur; as t) {
                        <div class="etape">{{ t.prenom }} {{ t.nom }} @if (t.telephone) { · <a [href]="'tel:' + t.telephone">{{ t.telephone }}</a> }</div>
                      }
                      @if (c.code_reception) {
                        <div class="code-reception" title="À donner au transporteur à la remise">Code de réception : <strong>{{ c.code_reception }}</strong></div>
                      }
                      @if (c.livraison.statut === 'en_cours') {
                        <a [routerLink]="['/suivi-livraison', c.livraison.id]" class="btn-secondary btn-sm" style="margin-left:.4rem">
                          📍 Suivre
                        </a>
                      }
                    } @else {
                      <span class="text-muted">—</span>
                    }
                  </td>
                  <td>
                    <div class="btn-group">
                      @if (peutFaire(c, 'modifier')) { <button class="btn-secondary btn-sm" (click)="ouvrirModifier(c)">Modifier</button> }
                      @if (peutFaire(c, 'annuler')) {
                        <button class="btn-danger btn-sm" [disabled]="actionEnCours() === c.id" (click)="annuler(c.id)">Annuler</button>
                      }
                      @if (peutFaire(c, 'confirmer_reception')) {
                        <button class="btn-success btn-sm" [disabled]="actionEnCours() === c.id" (click)="confirmerReception(c)">{{ libelle('confirmer_reception') }}</button>
                      }
                      @if (peutFaire(c, 'declarer_anomalie')) {
                        <button class="btn-ghost btn-sm" (click)="erreurAnomalie.set(null); anomaliePour.set(c)">{{ libelle('declarer_anomalie') }}</button>
                      }
                      @if (peutFaire(c, 'demander_annulation')) {
                        <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === c.id" (click)="demanderAnnulation(c)">{{ libelle('demander_annulation') }}</button>
                      }
                    </div>
                    @for (i of c.incidents ?? []; track i.id) {
                      <div class="etape">{{ libelleIncident(i.type) }} : <span class="badge badge-{{ i.statut }}">{{ i.statut | libelleStatut }}</span>
                        @if (i.decision) { — {{ libelleDecision(i.decision) }}@if (i.motif_decision) { ({{ i.motif_decision }}) } }</div>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>

      @if (anomaliePour(); as c) {
        <app-incident-form [titre]="'Problème à la réception — ' + c.numero_commande" sousTitre="La livraison est enregistrée et la commande passe en litige : l'entrepôt vous propose une solution."
          [types]="anomalies" [erreur]="erreurAnomalie()" [envoi]="actionEnCours() === c.id" (envoyer)="declarerAnomalie(c, $event)" (fermer)="anomaliePour.set(null)" />
      }

      <!-- Modal modification commande -->
      @if (commandeEnEdition()) {
        <div class="modal-overlay" (click)="fermerModal()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>Modifier la commande</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              <code class="code-lot">{{ commandeEnEdition()!.numero_commande }}</code>
              — {{ commandeEnEdition()!.produit }}
            </p>
            @if (erreurModal()) {
              <div class="alert alert-error">{{ erreurModal() }}</div>
            }
            <form [formGroup]="formModif" (ngSubmit)="sauvegarderModif()">
              <div class="form-row">
                <div class="form-group">
                  <label>Nouvelle quantité (kg) *</label>
                  <input type="number" formControlName="quantite" min="0.01" step="0.01" />
                </div>
              </div>
              @if (commandeEnEdition()!.prix_unitaire && formModif.value.quantite > 0) {
                <p class="montant">Nouveau total : <strong>{{ formModif.value.quantite * commandeEnEdition()!.prix_unitaire | number:'1.0-2' }} FCFA</strong>
                  (prix figé à la commande : {{ commandeEnEdition()!.prix_unitaire | number }} FCFA / kg)</p>
              }
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Enregistrement...' : 'Enregistrer' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModal()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .modal-box { --modal-largeur: 420px; }
    .montant { margin: 0 0 1rem; font-size: .95rem; }
    .etape { font-size: .8rem; color: #6b7280; margin-top: .25rem; }
    .code-reception { margin-top: .35rem; padding: .3rem .5rem; border-radius: 6px; background: #fef3c7; font-size: .85rem; }
    .code-reception strong { font-size: 1.1rem; letter-spacing: .2em; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class MesCommandesComponent implements OnInit {
  commandes         = signal<any[]>([]);
  chargement        = signal(true);
  erreurChargement  = signal<string | null>(null);
  actionEnCours     = signal<number | null>(null);
  commandeEnEdition = signal<any | null>(null);
  loadingModal      = signal(false);
  message           = signal<string | null>(null);
  erreur            = signal<string | null>(null);
  erreurModal       = signal<string | null>(null);
  formModif!: FormGroup;
  anomaliePour   = signal<any | null>(null);
  erreurAnomalie = signal<string | null>(null);
  readonly anomalies = ANOMALIES;
  readonly peutFaire = peutFaire;
  readonly libelleIncident = libelleIncident;

  libelle(a: ActionCommande): string { return LIBELLE_ACTION_COMMANDE[a]; }
  libelleDecision(d: string): string { return DECISION_PRISE[d] ?? d; }

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formModif = this.fb.group({
      quantite: [null, [Validators.required, Validators.min(0.01)]],
    });
    this.charger();
  }

  charger(): void {
    this.erreurChargement.set(null);
    this.api.getHistoriqueCommandes().subscribe({
      next: res => { this.commandes.set(res.commandes); this.chargement.set(false); },
      error: err => {
        this.erreurChargement.set(err.status === 0 ? 'Serveur injoignable. Vérifie ta connexion.' : err.error?.message ?? 'Impossible de charger tes commandes.');
        this.chargement.set(false);
      },
    });
  }

  annuler(id: number): void {
    if (!confirm('Confirmer l\'annulation de cette commande ?')) return;
    this.actionEnCours.set(id);
    this.api.annulerCommande(id).subscribe({
      next: () => {
        this.commandes.update(list => list.map(c => c.id === id ? { ...c, statut: 'annulee' } : c));
        this.actionEnCours.set(null);
        this.message.set('Commande annulée.');
        setTimeout(() => this.message.set(null), 3000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de l\'annulation.');
        this.actionEnCours.set(null);
      },
    });
  }

  ouvrirModifier(c: any): void {
    this.commandeEnEdition.set(c);
    this.formModif.reset({ quantite: c.quantite });
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.commandeEnEdition.set(null); }

  sauvegarderModif(): void {
    if (this.formModif.invalid) { this.formModif.markAllAsTouched(); return; }
    const c = this.commandeEnEdition()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    this.api.updateCommande(c.id, this.formModif.value).subscribe({
      next: res => {
        this.commandes.update(list => list.map(x => x.id === c.id ? { ...x, ...res.commande } : x));
        this.loadingModal.set(false);
        this.fermerModal();
        this.message.set('Commande mise à jour.');
        setTimeout(() => this.message.set(null), 3000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la modification.');
        this.loadingModal.set(false);
      },
    });
  }

  confirmerReception(c: any): void {
    if (!confirm(`Confirmer que vous avez bien reçu la commande ${c.numero_commande} (${c.quantite} kg de ${c.produit}) ? En cas de problème, utilisez plutôt « Signaler un problème ».`)) return;
    this.executer(c.id, this.api.confirmerReception(c.id), 'Réception confirmée. Merci !');
  }

  demanderAnnulation(c: any): void {
    const motif = demanderMotif(`Demander l'annulation de la commande ${c.numero_commande} ?\nL'entrepôt décide : l'annulation n'est plus possible une fois la marchandise chargée.`, false);
    if (motif === null) return;
    this.executer(c.id, this.api.demanderAnnulationCommande(c.id, motif || undefined), "Demande d'annulation envoyée à l'entrepôt.");
  }

  declarerAnomalie(c: any, saisie: { type: string; description: string }): void {
    this.actionEnCours.set(c.id);
    this.erreurAnomalie.set(null);
    this.api.declarerAnomalie(c.id, saisie.type, saisie.description).subscribe({
      next: () => { this.actionEnCours.set(null); this.anomaliePour.set(null); this.afficher("Problème signalé. L'entrepôt va vous proposer une solution."); this.charger(); },
      error: err => { this.actionEnCours.set(null); this.erreurAnomalie.set(err.error?.message ?? 'Envoi impossible.'); },
    });
  }

  private executer(id: number, requete: ReturnType<ApiService['getHistoriqueCommandes']>, succes: string): void {
    this.actionEnCours.set(id);
    this.erreur.set(null);
    requete.subscribe({
      next: () => { this.actionEnCours.set(null); this.afficher(succes); this.charger(); },
      error: err => {
        this.actionEnCours.set(null);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : action non enregistrée.' : err.error?.message ?? 'Action impossible.');
        this.charger();
      },
    });
  }

  private afficher(texte: string): void {
    this.message.set(texte);
    setTimeout(() => this.message.set(null), 4000);
  }
}
