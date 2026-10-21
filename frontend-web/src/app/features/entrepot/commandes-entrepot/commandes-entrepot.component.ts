import { Component, OnInit, computed, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';
import { ActionCommande, LIBELLE_ACTION_COMMANDE, libelleIncident, peutFaire } from '../../../shared/livraison/livraison';

/** Sections de la page, dans l'ordre du circuit. */
const SECTIONS: { titre: string; statuts: string[]; vide: string }[] = [
  { titre: 'À confirmer',                  statuts: ['en_attente'],                          vide: 'Aucune commande en attente.' },
  { titre: 'À préparer et à livrer',       statuts: ['confirmee', 'en_preparation', 'prete'], vide: 'Aucune commande à préparer.' },
  { titre: 'En livraison',                 statuts: ['en_livraison', 'litige'],              vide: 'Aucune livraison en cours.' },
  { titre: 'Terminées',                    statuts: ['receptionnee', 'annulee'],             vide: 'Aucune commande terminée.' },
];

@Component({
  selector: 'app-commandes-entrepot',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Commandes</h1>
        <span class="subtitle">{{ aTraiter() }} à traiter
          @if (incidentsOuverts() > 0) { · <a routerLink="/entrepot/incidents">{{ incidentsOuverts() }} incident(s) à décider</a> }</span>
      </div>

      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }
      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }

      @if (chargement()) {
        <p class="loading">Chargement...</p>
      } @else {
        @for (section of sections; track section.titre) {
          <div class="card">
            <h2>{{ section.titre }} <span class="compteur">{{ parSection(section.statuts).length }}</span></h2>
            @if (parSection(section.statuts).length === 0) {
              <p class="empty">{{ section.vide }}</p>
            } @else {
              <div class="table-scroll"><table class="table" appTableauResponsive>
                <thead>
                  <tr><th>Numéro</th><th>Produit</th><th>Quantité</th><th>Montant</th><th>Acheteur</th><th>Statut</th><th>Livraison</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  @for (c of parSection(section.statuts); track c.id) {
                    <tr>
                      <td><code class="code-lot">{{ c.numero_commande }}</code>
                        <br><small class="text-muted">{{ c.date_commande | date:'dd/MM/yyyy' }}</small>
                        @if (c.panier) { <br><small class="text-muted">Panier {{ c.panier }}</small> }</td>
                      <td><strong>{{ c.produit }}</strong></td>
                      <td>{{ c.quantite | number }} kg</td>
                      <td>{{ c.prix ? (c.prix | number:'1.0-2') + ' FCFA' : '—' }}
                        @if (c.prix_unitaire) { <br><small class="text-muted">{{ c.prix_unitaire | number }} FCFA / kg</small> }</td>
                      <td>{{ c.acheteur?.utilisateur?.prenom }} {{ c.acheteur?.utilisateur?.nom }}
                        @if (c.acheteur?.utilisateur?.telephone; as tel) { <br><a [href]="'tel:' + tel">{{ tel }}</a> }</td>
                      <td><span class="badge badge-{{ c.statut }}">{{ c.statut | libelleStatut }}</span>
                        @if (c.prochaine_etape) { <div class="text-muted">{{ c.prochaine_etape.libelle }}</div> }
                        @for (i of incidentsOuvertsDe(c); track i.id) {
                          <div class="incident">⚠️ {{ libelleIncident(i.type) }} — <a routerLink="/entrepot/incidents">décider</a></div>
                        }</td>
                      <td>
                        @if (c.livraison) {
                          <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut | libelleStatut }}</span>
                          <div class="text-muted">{{ c.livraison.transporteur?.utilisateur?.prenom }} {{ c.livraison.transporteur?.utilisateur?.nom }}</div>
                          @if (c.livraison.statut === 'en_cours') {
                            <a class="btn-secondary btn-sm" [routerLink]="['/suivi', 'livraison', c.livraison.id]">📍 Suivre</a>
                          }
                        } @else { <span class="text-muted">—</span> }
                      </td>
                      <td>
                        <div class="btn-group">
                          @for (a of actions(c); track a) {
                            <button [class]="classe(a)" [disabled]="actionEnCours() === c.id" (click)="agir(c, a)">{{ libelle(a) }}</button>
                          }
                        </div>
                      </td>
                    </tr>
                  }
                </tbody>
              </table></div>
            }
          </div>
        }
      }

      <!-- Organisation de la livraison (commande prête) ou changement de transporteur -->
      @if (cible(); as c) {
        <div class="modal-overlay" (click)="demanderFermeture()">
          <div class="modal-box" role="dialog" aria-modal="true" (click)="$event.stopPropagation()">
            <h2>{{ modeModal() === 'organiser' ? 'Organiser la livraison' : 'Changer de transporteur' }}</h2>
            <p class="note">Commande <code class="code-lot">{{ c.numero_commande }}</code> — {{ c.produit }} / {{ c.quantite }} kg.
              Le transporteur choisi doit accepter la mission ; il est prévenu tout de suite.</p>
            @if (erreurModal()) { <div class="alert alert-error" role="alert">{{ erreurModal() }}</div> }
            <form [formGroup]="form" (ngSubmit)="valider()">
              <div class="form-group">
                <label for="transporteur">Transporteur * <small class="text-muted">(les moins occupés d'abord)</small></label>
                <select id="transporteur" formControlName="transporteur_id">
                  <option [ngValue]="null">— Choisir —</option>
                  @for (t of transporteurs(); track t.id) {
                    <option [ngValue]="t.id" [disabled]="t.id === c.livraison?.transporteur_id && modeModal() === 'changer'">
                      {{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }}{{ t.type_vehicule ? ' (' + t.type_vehicule + ')' : '' }} — {{ t.missions_actives ?? 0 }} mission(s) en cours
                    </option>
                  }
                </select>
                @if (form.get('transporteur_id')?.invalid && form.get('transporteur_id')?.touched) {
                  <span class="field-error">Choisissez le transporteur qui recevra la mission.</span>
                }
              </div>
              @if (modeModal() === 'organiser') {
                <div class="form-row">
                  <div class="form-group">
                    <label for="origine">Départ</label>
                    <input id="origine" type="text" formControlName="origine" placeholder="Par défaut : l'entrepôt" />
                  </div>
                  <div class="form-group">
                    <label for="destination">Destination</label>
                    <input id="destination" type="text" formControlName="destination" placeholder="Par défaut : l'adresse de l'acheteur" />
                  </div>
                </div>
                <div class="form-group">
                  <label for="date_depart">Départ prévu</label>
                  <input id="date_depart" type="datetime-local" formControlName="date_depart" />
                </div>
              } @else {
                <div class="form-group">
                  <label for="motif">Motif (facultatif)</label>
                  <textarea id="motif" formControlName="motif" rows="2" maxlength="500"></textarea>
                </div>
              }
              <div class="btn-group">
                <button type="submit" class="btn-success" [disabled]="loadingModal()">{{ loadingModal() ? 'Envoi...' : (modeModal() === 'organiser' ? 'Envoyer la mission' : 'Réassigner') }}</button>
                <button type="button" class="btn-ghost" (click)="demanderFermeture()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .modal-box { --modal-largeur: 560px; }
    select, textarea { padding:.55rem .8rem; border:1px solid #e5e7eb; border-radius:6px; font-size:.9rem; width:100%; font-family:inherit; }
    .field-error { display:block; color:#dc2626; font-size:.8rem; margin-top:.3rem; }
    .note { font-size:.85rem; color:#6b7280; margin:0 0 1rem; }
    .text-muted { color:#6b7280; font-size:.8rem; }
    .incident { color:#b45309; font-size:.8rem; margin-top:.25rem; }
    .compteur { display:inline-block; min-width:1.6rem; padding:0 .45rem; border-radius:999px; background:#d8f3dc; color:#1b4332; font-size:.85rem; text-align:center; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class CommandesEntrepotComponent implements OnInit {
  readonly sections = SECTIONS;
  commandes     = signal<any[]>([]);
  transporteurs = signal<any[]>([]);
  chargement    = signal(true);
  actionEnCours = signal<number | null>(null);
  message       = signal<string | null>(null);
  erreur        = signal<string | null>(null);
  erreurModal   = signal<string | null>(null);
  loadingModal  = signal(false);
  cible         = signal<any | null>(null);
  modeModal     = signal<'organiser' | 'changer'>('organiser');
  form!: FormGroup;

  aTraiter         = computed(() => this.commandes().filter(c => (c.actions ?? []).some((a: string) => ['confirmer', 'preparer', 'marquer_prete', 'organiser_livraison'].includes(a))).length);
  incidentsOuverts = computed(() => this.commandes().reduce((n, c) => n + this.incidentsOuvertsDe(c).length, 0));

  readonly libelleIncident = libelleIncident;
  private cleLivraison = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.form = this.fb.group({ transporteur_id: [null, Validators.required], origine: [''], destination: [''], date_depart: [''], motif: [''] });
    this.charger();
  }

  charger(): void {
    this.api.getCommandesEntrepot().subscribe({
      next: res => { this.commandes.set(res.commandes ?? []); this.chargement.set(false); },
      error: err => {
        this.chargement.set(false);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger les commandes.');
      },
    });
  }

  parSection(statuts: string[]): any[] { return this.commandes().filter(c => statuts.includes(c.statut)); }

  incidentsOuvertsDe(c: any): any[] {
    return [...(c.incidents ?? []), ...(c.livraison?.incidents ?? [])].filter(i => i.statut === 'ouvert');
  }

  actions(c: any): ActionCommande[] {
    const ordre: ActionCommande[] = ['confirmer', 'preparer', 'marquer_prete', 'organiser_livraison', 'changer_transporteur', 'refuser', 'annuler_commande'];
    return ordre.filter(a => peutFaire(c, a));
  }

  libelle(a: ActionCommande): string { return LIBELLE_ACTION_COMMANDE[a]; }

  classe(a: ActionCommande): string {
    return ['refuser', 'annuler_commande'].includes(a) ? 'btn-ghost btn-sm' : a === 'changer_transporteur' ? 'btn-secondary btn-sm' : 'btn-success btn-sm';
  }

  agir(c: any, a: ActionCommande): void {
    switch (a) {
      case 'confirmer':
        if (!confirm(`Confirmer la commande ${c.numero_commande} ? ${c.quantite} kg seront réservés dans le stock.`)) return;
        return this.executer(c.id, this.api.confirmerCommande(c.id), 'Commande confirmée. La quantité est réservée.');
      case 'preparer':
        return this.executer(c.id, this.api.preparerCommande(c.id), 'Préparation lancée. L\'acheteur a été prévenu.');
      case 'marquer_prete':
        return this.executer(c.id, this.api.marquerCommandePrete(c.id), 'Commande prête. Organisez maintenant sa livraison.');
      case 'organiser_livraison':
      case 'changer_transporteur':
        return this.ouvrir(c, a === 'organiser_livraison' ? 'organiser' : 'changer');
      case 'refuser': {
        const motif = demanderMotif(`Refuser la commande ${c.numero_commande} ?\nElle sera annulée et l'acheteur notifié avec le motif.`, true);
        if (motif === null) return;
        return this.executer(c.id, this.api.refuserCommande(c.id, motif), 'Commande refusée. L\'acheteur a été notifié.');
      }
      case 'annuler_commande': {
        const motif = demanderMotif(`Annuler la commande ${c.numero_commande} ?\nLa réservation de ${c.quantite} kg sera libérée, la livraison éventuelle annulée (transporteur prévenu) et l'acheteur notifié. Aucun remboursement n'est effectué automatiquement.`, true);
        if (motif === null) return;
        return this.executer(c.id, this.api.annulerConfirmationCommande(c.id, motif), 'Commande annulée, réservation libérée.');
      }
    }
  }

  private ouvrir(c: any, mode: 'organiser' | 'changer'): void {
    this.modeModal.set(mode);
    this.cible.set(c);
    this.form.reset({ transporteur_id: null, origine: '', destination: '', date_depart: '', motif: '' });
    this.cleLivraison.renouveler();
    this.erreurModal.set(null);
    // Charge actualisée à chaque ouverture : le choix se fait sur l'occupation réelle.
    this.api.getTransporteurs().subscribe({ next: res => this.transporteurs.set(res.transporteurs ?? []), error: () => {} });
  }

  valider(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    const c = this.cible()!;
    const v = this.form.value;
    this.loadingModal.set(true);
    this.erreurModal.set(null);
    const requete = this.modeModal() === 'organiser'
      ? this.api.createLivraison({
          commande_id: c.id, transporteur_id: v.transporteur_id,
          ...(v.origine ? { origine: v.origine } : {}), ...(v.destination ? { destination: v.destination } : {}), ...(v.date_depart ? { date_depart: v.date_depart } : {}),
        }, this.cleLivraison.valeur)
      : this.api.changerTransporteurLivraison(c.livraison.id, v.transporteur_id, v.motif || undefined);
    requete.subscribe({
      next: () => {
        this.cleLivraison.renouveler();
        this.loadingModal.set(false);
        this.cible.set(null);
        this.afficher(this.modeModal() === 'organiser' ? 'Mission envoyée au transporteur : il doit l\'accepter.' : 'Transporteur changé. Les deux transporteurs ont été prévenus.');
        this.charger();
      },
      error: err => {
        this.loadingModal.set(false);
        this.erreurModal.set(err.status === 0 ? 'Réponse du serveur perdue : réessayez, la livraison ne sera pas créée deux fois.' : err.error?.message ?? 'Action impossible.');
      },
    });
  }

  demanderFermeture(): void {
    if (abandonConfirme(this.form)) this.cible.set(null);
  }

  private executer(id: number, requete: ReturnType<ApiService['getCommandesEntrepot']>, succes: string): void {
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
