import { Component, OnInit, computed, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { DECISION_AVEC_MOTIF, LIBELLE_DECISION, libelleIncident } from '../../../shared/livraison/livraison';

const SUJET: Record<string, string> = { livraison: 'Livraison', reservation: 'Dépôt', commande: 'Commande' };

/**
 * Incidents des deux circuits pour le gestionnaire : chaque incident ouvert
 * arrive avec les décisions possibles (calculées par le serveur).
 */
@Component({
  selector: 'app-incidents',
  standalone: true,
  imports: [LibelleStatutPipe, DatePipe, FormsModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Incidents</h1>
        <span class="subtitle">{{ ouverts().length }} à décider</span>
      </div>

      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }
      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }

      @if (chargement()) {
        <p class="loading">Chargement...</p>
      } @else {
        <div class="card">
          <h2>À décider</h2>
          @if (ouverts().length === 0) { <p class="empty">Aucun incident ouvert.</p> }
          <div class="cards-grid">
            @for (i of ouverts(); track i.id) {
              <article class="product-card">
                <div class="product-header">
                  <h3>{{ libelleIncident(i.type) }}</h3>
                  <span class="badge badge-probleme">{{ sujet(i) }} {{ i.sujet?.numero }}</span>
                </div>
                <div class="product-info">
                  @if (i.sujet?.commande) { <div class="info-row"><span>Commande</span><span>{{ i.sujet.commande }}</span></div> }
                  @if (i.sujet?.produit) { <div class="info-row"><span>Produit</span><span>{{ i.sujet.produit }}</span></div> }
                  <div class="info-row"><span>Déclaré par</span><span>{{ i.declarant?.prenom }} {{ i.declarant?.nom }} · {{ i.declarant?.role | libelleStatut }}</span></div>
                  <div class="info-row"><span>Le</span><span>{{ i.created_at | date:'dd/MM/yyyy HH:mm' }}</span></div>
                  <p class="description">« {{ i.description }} »</p>
                </div>
                <div class="form-group">
                  <label [for]="'decision-' + i.id">Décision</label>
                  <select [id]="'decision-' + i.id" [(ngModel)]="choix[i.id]" (ngModelChange)="preparer(i)">
                    <option [ngValue]="undefined">— Choisir —</option>
                    @for (d of i.decisions; track d) { <option [ngValue]="d">{{ libelleDecision(d) }}</option> }
                  </select>
                </div>
                @if (choix[i.id] === 'changer_transporteur') {
                  <div class="form-group">
                    <label [for]="'transporteur-' + i.id">Nouveau transporteur</label>
                    <select [id]="'transporteur-' + i.id" [(ngModel)]="transporteur[i.id]">
                      <option [ngValue]="undefined">— Choisir —</option>
                      @for (t of transporteurs(); track t.id) {
                        <option [ngValue]="t.id">{{ t.utilisateur?.prenom }} {{ t.utilisateur?.nom }} — {{ t.missions_actives ?? 0 }} mission(s)</option>
                      }
                    </select>
                  </div>
                }
                @if (choix[i.id]) {
                  <div class="form-group">
                    <label [for]="'motif-' + i.id">Motif {{ motifObligatoire(i) ? '*' : '(facultatif)' }}</label>
                    <textarea [id]="'motif-' + i.id" rows="2" maxlength="500" [(ngModel)]="motif[i.id]"></textarea>
                  </div>
                }
                <button class="btn-primary btn-full" [disabled]="!choix[i.id] || actionEnCours() === i.id" (click)="decider(i)">
                  {{ actionEnCours() === i.id ? 'Envoi…' : 'Enregistrer la décision' }}
                </button>
              </article>
            }
          </div>
        </div>

        @if (traites().length > 0) {
          <div class="card">
            <h2>Traités</h2>
            <ul class="traites">
              @for (i of traites(); track i.id) {
                <li>{{ sujet(i) }} {{ i.sujet?.numero }} — {{ libelleIncident(i.type) }} → <strong>{{ libelleDecision(i.decision) }}</strong>
                  @if (i.motif_decision) { ({{ i.motif_decision }}) } <small>{{ i.resolu_le | date:'dd/MM/yyyy HH:mm' }}</small></li>
              }
            </ul>
          </div>
        }
      }
    </div>
  `,
  styles: [`
    select, textarea { padding:.55rem .8rem; border:1px solid #e5e7eb; border-radius:6px; font-size:.9rem; width:100%; font-family:inherit; }
    .description { margin:.5rem 0 0; font-style:italic; color:#374151; }
    .traites { margin:0; padding-left:1.1rem; display:grid; gap:.4rem; font-size:.9rem; }
    .form-group { margin-top:.6rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class IncidentsComponent implements OnInit {
  incidents     = signal<any[]>([]);
  transporteurs = signal<any[]>([]);
  chargement    = signal(true);
  actionEnCours = signal<number | null>(null);
  message       = signal<string | null>(null);
  erreur        = signal<string | null>(null);
  choix: Record<number, string | undefined> = {};
  motif: Record<number, string> = {};
  transporteur: Record<number, number | undefined> = {};

  ouverts = computed(() => this.incidents().filter(i => i.statut === 'ouvert'));
  traites = computed(() => this.incidents().filter(i => i.statut !== 'ouvert'));

  readonly libelleIncident = libelleIncident;

  constructor(private api: ApiService) {}

  ngOnInit(): void { this.charger(); }

  charger(): void {
    this.api.getIncidents().subscribe({
      next: res => { this.incidents.set(res.incidents ?? []); this.chargement.set(false); },
      error: err => {
        this.chargement.set(false);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger les incidents.');
      },
    });
  }

  sujet(i: any): string { return SUJET[i.sujet_type] ?? i.sujet_type; }
  libelleDecision(d: string): string { return LIBELLE_DECISION[d] ?? d; }
  motifObligatoire(i: any): boolean { return DECISION_AVEC_MOTIF.includes(this.choix[i.id] ?? ''); }

  preparer(i: any): void {
    if (this.choix[i.id] === 'changer_transporteur' && this.transporteurs().length === 0) {
      this.api.getTransporteurs().subscribe({ next: res => this.transporteurs.set(res.transporteurs ?? []), error: () => {} });
    }
  }

  decider(i: any): void {
    const decision = this.choix[i.id]!;
    const motif = (this.motif[i.id] ?? '').trim();
    if (this.motifObligatoire(i) && !motif) { this.erreur.set('Indiquez le motif de la décision.'); return; }
    if (decision === 'changer_transporteur' && !this.transporteur[i.id]) { this.erreur.set('Choisissez le nouveau transporteur.'); return; }
    if (!confirm(`Confirmer la décision « ${this.libelleDecision(decision)} » ? Les personnes concernées seront prévenues.`)) return;
    this.actionEnCours.set(i.id);
    this.erreur.set(null);
    this.api.resoudreIncident(i.id, {
      decision, ...(motif ? { motif } : {}), ...(decision === 'changer_transporteur' ? { transporteur_id: this.transporteur[i.id] } : {}),
    }).subscribe({
      next: () => {
        this.actionEnCours.set(null);
        this.message.set('Décision enregistrée. Les personnes concernées ont été prévenues.');
        setTimeout(() => this.message.set(null), 4000);
        this.charger();
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : décision non enregistrée.' : err.error?.message ?? 'Décision impossible.');
        this.charger();
      },
    });
  }
}
