import { Component, OnDestroy, OnInit, computed, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';

/** Actualisation automatique de la liste (une assignation faite par l'entrepôt apparaît sans action). */
export const INTERVALLE_ACTUALISATION_MS = 60_000;

@Component({
  selector: 'app-transporteur-missions',
  standalone: true,
  imports: [LibelleStatutPipe, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Mes missions</h1>
        <span class="subtitle">Acheminements vers l'entrepôt et livraisons aux acheteurs qui vous sont assignés</span>
      </div>

      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }
      @if (erreurAction()) { <div class="alert alert-error" role="alert">{{ erreurAction() }}</div> }

      @if (chargement() && !chargee()) {
        <p class="loading">Chargement des missions…</p>
      } @else if (erreur() && !chargee()) {
        <div class="card">
          <div class="alert alert-error" role="alert">{{ erreur() }}</div>
          <button class="btn-primary" (click)="charger()">Réessayer</button>
        </div>
      } @else {
        @if (erreur()) {
          <div class="alert alert-error" role="alert">{{ erreur() }} Les missions affichées datent de la dernière actualisation.</div>
        }

        <div class="card">
          <h2>Acheminements vers l'entrepôt <span class="compteur">{{ acheminementsActifs().length }}</span></h2>
          @if (acheminementsActifs().length === 0) {
            <p class="empty">Aucun acheminement en cours. Les nouvelles missions apparaissent ici dès que l'entrepôt vous les assigne.</p>
          } @else {
            <div class="cards-grid">
              @for (r of acheminementsActifs(); track r.id) {
                <article class="product-card mission">
                  <div class="product-header">
                    <h3>{{ r.produit }} — {{ r.quantite_reservee | number }} kg</h3>
                    <span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span>
                  </div>
                  <div class="product-info">
                    <div class="info-row"><span>Réservation</span><span><code class="code-lot">{{ r.numero_reservation }}</code></span></div>
                    <div class="info-row"><span>Chez le producteur</span><span>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}
                      @if (r.producteur?.utilisateur?.telephone) { · <a [href]="'tel:' + r.producteur.utilisateur.telephone">{{ r.producteur.utilisateur.telephone }}</a> }</span></div>
                    <div class="info-row"><span>Vers l'entrepôt</span><span>{{ r.entrepot?.nom_entrepot }}@if (r.entrepot?.localisation) { · {{ r.entrepot.localisation }} }</span></div>
                    <div class="info-row"><span>À partir du</span><span>{{ r.date_debut | date:'dd/MM/yyyy' }}</span></div>
                  </div>
                  <button class="btn-success btn-full" [disabled]="actionEnCours() === 'r' + r.id" (click)="signalerArrivee(r)">
                    {{ actionEnCours() === 'r' + r.id ? 'Envoi…' : 'Signaler l\\'arrivée à l\\'entrepôt' }}
                  </button>
                </article>
              }
            </div>
          }
        </div>

        <div class="card">
          <h2>Livraisons aux acheteurs <span class="compteur">{{ livraisonsActives().length }}</span></h2>
          @if (livraisonsActives().length === 0) {
            <p class="empty">Aucune livraison en cours.</p>
          } @else {
            <div class="cards-grid">
              @for (l of livraisonsActives(); track l.id) {
                <article class="product-card mission">
                  <div class="product-header">
                    <h3>{{ l.commande?.produit }} — {{ l.commande?.quantite | number }} kg</h3>
                    <span class="badge badge-{{ l.statut }}">{{ l.statut | libelleStatut }}</span>
                  </div>
                  <div class="product-info">
                    <div class="info-row"><span>Livraison</span><span><code class="code-lot">{{ l.numero_livraison }}</code></span></div>
                    <div class="info-row"><span>Départ</span><span>{{ l.origine }}</span></div>
                    <div class="info-row"><span>Destination</span><span>{{ l.destination }}</span></div>
                    @if (l.date_depart) { <div class="info-row"><span>Départ prévu</span><span>{{ l.date_depart | date:'dd/MM/yyyy HH:mm' }}</span></div> }
                  </div>
                  <div class="btn-group">
                    @if (l.statut === 'en_attente') {
                      <button class="btn-primary" [disabled]="actionEnCours() === 'l' + l.id" (click)="changerStatut(l, 'en_cours')">Démarrer la livraison</button>
                    }
                    @if (l.statut === 'en_cours') {
                      <button class="btn-success" [disabled]="actionEnCours() === 'l' + l.id" (click)="changerStatut(l, 'livree')">Marquer comme livrée</button>
                    }
                    @if (l.statut === 'en_attente' || l.statut === 'en_cours') {
                      <button class="btn-ghost" [disabled]="actionEnCours() === 'l' + l.id" (click)="signalerProbleme(l)">Signaler un problème</button>
                    }
                  </div>
                </article>
              }
            </div>
            <p class="note">Le suivi GPS en temps réel de l'acheteur est alimenté par l'application mobile SenAgriLink.</p>
          }
        </div>

        @if (terminees().length > 0) {
          <div class="card">
            <h2>Missions terminées</h2>
            <ul class="terminees">
              @for (m of terminees(); track m.cle) {
                <li><code class="code-lot">{{ m.numero }}</code> {{ m.libelle }} <span class="badge badge-{{ m.statut }}">{{ m.statut | libelleStatut }}</span></li>
              }
            </ul>
          </div>
        }
      }
    </div>
  `,
  styles: [`
    .compteur { display:inline-block; min-width:1.6rem; padding:0 .45rem; border-radius:999px; background:#d8f3dc; color:#1b4332; font-size:.85rem; text-align:center; }
    .mission .product-header { display:flex; justify-content:space-between; gap:.5rem; align-items:flex-start; flex-wrap:wrap; }
    .mission .btn-group { margin-top:.75rem; }
    .note { margin:.75rem 0 0; font-size:.8rem; color:#6b7280; }
    .terminees { margin:0; padding-left:1.1rem; display:grid; gap:.4rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class TransporteurMissionsComponent implements OnInit, OnDestroy {
  reservations  = signal<any[]>([]);
  livraisons    = signal<any[]>([]);
  chargement    = signal(false);
  chargee       = signal(false);
  erreur        = signal<string | null>(null);
  erreurAction  = signal<string | null>(null);
  message       = signal<string | null>(null);
  actionEnCours = signal<string | null>(null);

  acheminementsActifs = computed(() => this.reservations().filter(r => r.statut === 'confirmee'));
  livraisonsActives   = computed(() => this.livraisons().filter(l => ['en_attente', 'en_cours', 'probleme'].includes(l.statut)));
  terminees = computed(() => [
    ...this.reservations().filter(r => r.statut !== 'confirmee')
      .map(r => ({ cle: 'r' + r.id, numero: r.numero_reservation, libelle: `${r.produit} → ${r.entrepot?.nom_entrepot ?? ''}`, statut: r.statut })),
    ...this.livraisons().filter(l => l.statut === 'livree')
      .map(l => ({ cle: 'l' + l.id, numero: l.numero_livraison, libelle: `${l.commande?.produit ?? ''} → ${l.destination}`, statut: l.statut })),
  ]);

  private minuteur?: ReturnType<typeof setInterval>;
  private readonly surRetourAuPremierPlan = () => { if (document.visibilityState === 'visible') this.charger(); };

  constructor(private api: ApiService) {}

  ngOnInit(): void {
    this.charger();
    // PWA rouverte ou onglet revenu au premier plan : la liste est rafraîchie.
    document.addEventListener('visibilitychange', this.surRetourAuPremierPlan);
    this.minuteur = setInterval(() => this.charger(), INTERVALLE_ACTUALISATION_MS);
  }

  ngOnDestroy(): void {
    document.removeEventListener('visibilitychange', this.surRetourAuPremierPlan);
    clearInterval(this.minuteur);
  }

  charger(): void {
    if (this.chargement()) return;
    this.chargement.set(true);
    forkJoin({ reservations: this.api.getReservations(), livraisons: this.api.getLivraisons() }).subscribe({
      next: ({ reservations, livraisons }) => {
        this.reservations.set(reservations.reservations ?? []);
        this.livraisons.set(livraisons.livraisons ?? []);
        this.erreur.set(null);
        this.chargee.set(true);
        this.chargement.set(false);
      },
      error: err => {
        this.erreur.set(err.status === 0
          ? 'Serveur injoignable. Vérifiez votre connexion.'
          : err.error?.message ?? 'Impossible de charger vos missions.');
        this.chargement.set(false);
      },
    });
  }

  signalerArrivee(r: any): void {
    if (!confirm(`Confirmer l'arrivée de ${r.produit} (${r.quantite_reservee} kg) à l'entrepôt ${r.entrepot?.nom_entrepot ?? ''} ?\n\nLe gestionnaire sera notifié pour vérifier la marchandise. Cette action ne peut pas être annulée.`)) return;
    this.executer('r' + r.id, this.api.signalerArriveeReservation(r.id), 'Arrivée signalée. Le gestionnaire a été notifié.');
  }

  changerStatut(l: any, statut: 'en_cours' | 'livree'): void {
    const question = statut === 'en_cours'
      ? `Démarrer la livraison ${l.numero_livraison} ? L'acheteur sera informé du départ. Le transporteur ne pourra plus être changé.`
      : `Confirmer la remise de la livraison ${l.numero_livraison} à l'acheteur ? Cette action est définitive.`;
    if (!confirm(question)) return;
    this.executer('l' + l.id, this.api.updateStatutLivraison(l.id, statut), statut === 'en_cours' ? 'Livraison démarrée.' : 'Livraison terminée.');
  }

  signalerProbleme(l: any): void {
    if (!confirm(`Signaler un problème sur la livraison ${l.numero_livraison} ? L'acheteur en sera informé.`)) return;
    this.executer('l' + l.id, this.api.signalerProbleme(l.id), 'Problème signalé.');
  }

  private executer(cle: string, action: ReturnType<ApiService['getReservations']>, succes: string): void {
    this.actionEnCours.set(cle);
    this.erreurAction.set(null);
    action.subscribe({
      next: () => {
        this.actionEnCours.set(null);
        this.message.set(succes);
        setTimeout(() => this.message.set(null), 4000);
        this.charger();
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreurAction.set(err.status === 0 ? 'Serveur injoignable : action non enregistrée, réessayez.' : err.error?.message ?? 'Action impossible.');
        this.charger();
      },
    });
  }
}
