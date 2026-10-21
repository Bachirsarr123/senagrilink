import { RouterLink } from '@angular/router';
import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { PartagePositionService } from '../../../shared/suivi/partage-position';
import { DecimalPipe } from '@angular/common';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { demanderMotif } from '../../../shared/confirmation/confirmation';
import { ActionDepot, CONFIRMATION_ETAPE, ETAPE_DE_L_ACTION, LIBELLE_ACTION, peut } from '../../../shared/depot/depot';
import {
  ActionLivraison, CONFIRMATION_ETAPE_LIVRAISON, ETAPE_LIVRAISON, LIBELLE_ACTION_LIVRAISON, TYPES_INCIDENT, libelleIncident, peutFaire,
} from '../../../shared/livraison/livraison';
import { IncidentFormComponent } from '../../../shared/incident/incident-form';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';

/** Acheminement en cours pour le transporteur : proposé, accepté, en collecte ou en route. */
const ACHEMINEMENT_ACTIF = ['confirmee', 'prise_en_charge', 'au_point_collecte', 'chargee', 'en_route'];
/** Avant le chargement, le transporteur se rend chez le producteur ; ensuite, à l'entrepôt. */
const VERS_LE_PRODUCTEUR = ['confirmee', 'prise_en_charge', 'au_point_collecte'];

/** Livraison en cours : proposée, acceptée, chargée, en route ou arrivée chez l'acheteur. */
const LIVRAISON_ACTIVE = ['en_attente', 'prise_en_charge', 'chargee', 'en_cours', 'arrivee'];

/** Actualisation automatique de la liste (une assignation faite par l'entrepôt apparaît sans action). */
export const INTERVALLE_ACTUALISATION_MS = 60_000;

@Component({
  selector: 'app-transporteur-missions',
  standalone: true,
  imports: [RouterLink, LibelleStatutPipe, DecimalPipe, IncidentFormComponent],
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
                <article class="product-card mission" [class.a-accepter]="peut(r, 'accepter_mission')">
                  <div class="product-header">
                    <h3>{{ r.produit }} — {{ r.quantite_reservee | number }} kg</h3>
                    @if (peut(r, 'accepter_mission')) { <span class="badge badge-en_attente">Nouvelle mission à accepter</span> }
                    @else { <span class="badge badge-{{ r.statut }}">{{ r.statut | libelleStatut }}</span> }
                  </div>
                  <div class="product-info">
                    <div class="info-row"><span>Réservation</span><span><code class="code-lot">{{ r.numero_reservation }}</code></span></div>
                    @if (r.production?.code_tracabilite) { <div class="info-row"><span>Lot</span><span><code class="code-lot">{{ r.production.code_tracabilite }}</code></span></div> }
                    <div class="info-row"><span>Chez le producteur</span><span>{{ r.producteur?.utilisateur?.prenom }} {{ r.producteur?.utilisateur?.nom }}
                      @if (r.producteur?.utilisateur?.telephone) { · <a [href]="'tel:' + r.producteur.utilisateur.telephone">{{ r.producteur.utilisateur.telephone }}</a> }
                      @if (r.producteur?.utilisateur?.adresse) { <br><small>{{ r.producteur.utilisateur.adresse }}</small> }</span></div>
                    <div class="info-row"><span>Vers l'entrepôt</span><span>{{ r.entrepot?.nom_entrepot }}@if (r.entrepot?.localisation) { · {{ r.entrepot.localisation }} }</span></div>
                    @if (r.incidents?.length) { <div class="info-row"><span>Incident</span><span>{{ libelleIncident(r.incidents[0].type) }} — en attente de l'entrepôt</span></div> }
                    @if (r.prochaine_etape) { <div class="info-row"><span>Étape suivante</span><span>{{ r.prochaine_etape.libelle }}</span></div> }
                  </div>
                  @if (r.statut === 'en_route') {
                    <div class="partage" [class.partage--actif]="partage.partage('reservation', r.id)">
                      @if (partage.partage('reservation', r.id)) {
                        <span>📡 Position partagée depuis ce navigateur — gardez la page ouverte@if (partage.enAttente()) { ({{ partage.enAttente() }} en attente d'envoi) }.</span>
                        <button class="btn-ghost btn-sm" (click)="partage.arreter()">Arrêter</button>
                      } @else {
                        <span>Sur téléphone, l'application partage votre position même écran verrouillé.</span>
                        <button class="btn-secondary btn-sm" (click)="partager('reservation', r.id, r.numero_reservation)">Partager ma position depuis ce navigateur</button>
                      }
                      @if (partage.erreur(); as e) { <span class="partage__erreur">{{ e }}</span> }
                    </div>
                  }
                  @if (itineraire(r); as lien) {
                    <a class="btn-secondary btn-full lien-itineraire" [href]="lien" target="_blank" rel="noopener">{{ versLeProducteur(r) ? 'Itinéraire vers le producteur' : "Itinéraire vers l'entrepôt" }}</a>
                  }
                  <div class="btn-group">
                    @for (a of actionsTransporteur(r); track a) {
                      <button [class]="a === 'refuser_mission' || a === 'declarer_incident' ? 'btn-ghost' : 'btn-success'" [disabled]="actionEnCours() === 'r' + r.id" (click)="agir(r, a)">
                        {{ actionEnCours() === 'r' + r.id ? 'Envoi…' : libelle(a) }}
                      </button>
                    }
                  </div>
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
                <article class="product-card mission" [class.a-accepter]="peutFaire(l, 'accepter_mission')">
                  <div class="product-header">
                    <h3>{{ l.commande?.produit }} — {{ l.commande?.quantite | number }} kg</h3>
                    @if (peutFaire(l, 'accepter_mission')) { <span class="badge badge-en_attente">Nouvelle livraison à accepter</span> }
                    @else { <span class="badge badge-{{ l.statut }}">{{ l.statut | libelleStatut }}</span> }
                  </div>
                  @if (l.incidents?.length) {
                    <div class="alert alert-error" role="status">Incident déclaré : {{ libelleIncident(l.incidents[0].type) }}. L'entrepôt décide de la suite.</div>
                  }
                  <div class="product-info">
                    <div class="info-row"><span>Livraison</span><span><a [routerLink]="['/commandes', l.commande_id]" title="Ouvrir la fiche"><code class="code-lot">{{ l.numero_livraison }}</code></a></span></div>
                    <div class="info-row"><span>Charger à</span><span>{{ l.commande?.stock?.entrepot?.nom_entrepot ?? l.origine }}
                      @if (l.commande?.stock?.entrepot?.utilisateur?.telephone; as tel) { · <a [href]="'tel:' + tel">{{ tel }}</a> }</span></div>
                    <div class="info-row"><span>Livrer à</span><span>{{ l.commande?.acheteur?.utilisateur?.prenom }} {{ l.commande?.acheteur?.utilisateur?.nom }}
                      @if (l.commande?.acheteur?.utilisateur?.telephone; as tel) { · <a [href]="'tel:' + tel">{{ tel }}</a> }
                      <br><small>{{ l.destination }}</small></span></div>
                    @if (l.prochaine_etape) { <div class="info-row"><span>Étape suivante</span><span>{{ l.prochaine_etape.libelle }}</span></div> }
                  </div>
                  @if (l.statut === 'en_cours') {
                    <div class="partage" [class.partage--actif]="partage.partage('livraison', l.id)">
                      @if (partage.partage('livraison', l.id)) {
                        <span>📡 Position partagée depuis ce navigateur — gardez la page ouverte@if (partage.enAttente()) { ({{ partage.enAttente() }} en attente d'envoi) }.</span>
                        <button class="btn-ghost btn-sm" (click)="partage.arreter()">Arrêter</button>
                      } @else {
                        <span>Sur téléphone, l'application partage votre position même écran verrouillé.</span>
                        <button class="btn-secondary btn-sm" (click)="partager('livraison', l.id, l.numero_livraison)">Partager ma position depuis ce navigateur</button>
                      }
                      @if (partage.erreur(); as e) { <span class="partage__erreur">{{ e }}</span> }
                    </div>
                  }
                  @if (itineraireLivraison(l); as lien) {
                    <a class="btn-secondary btn-full lien-itineraire" [href]="lien" target="_blank" rel="noopener">{{ versLEntrepot(l) ? "Itinéraire vers l'entrepôt" : "Itinéraire vers l'acheteur" }}</a>
                  }
                  @if (peutFaire(l, 'confirmer_par_code')) {
                    <div class="remise">
                      <label [for]="'code-' + l.id">Code de réception donné par l'acheteur</label>
                      <div class="btn-group">
                        <input [id]="'code-' + l.id" #code inputmode="numeric" maxlength="4" placeholder="4 chiffres" />
                        <button class="btn-success" [disabled]="actionEnCours() === 'l' + l.id" (click)="remettre(l, code.value)">{{ libelleLivraison('confirmer_par_code') }}</button>
                      </div>
                    </div>
                  }
                  <div class="btn-group">
                    @for (a of actionsLivraison(l); track a) {
                      <button [class]="a === 'refuser_mission' || a === 'declarer_incident' ? 'btn-ghost' : 'btn-success'" [disabled]="actionEnCours() === 'l' + l.id" (click)="agirLivraison(l, a)">
                        {{ actionEnCours() === 'l' + l.id ? 'Envoi…' : libelleLivraison(a) }}
                      </button>
                    }
                  </div>
                </article>
              }
            </div>
            <p class="note">Le suivi GPS en temps réel de l'acheteur est alimenté par l'application mobile SenAgriLink.</p>
          }
        </div>

        @if (incidentPour(); as cible) {
          <app-incident-form [titre]="'Déclarer un incident — ' + cible.numero" sousTitre="L'entrepôt est prévenu et décide de la suite. En cas de panne ou d'accès impossible, le transport est suspendu jusqu'à sa décision."
            [types]="typesIncident(cible.sujet_type)" [erreur]="erreurIncident()" [envoi]="actionEnCours() === 'incident'"
            (envoyer)="declarer(cible, $event)" (fermer)="incidentPour.set(null)" />
        }

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
    .a-accepter { border:2px solid #f59e0b; }
    .partage { margin-top:.75rem; padding:.5rem .65rem; border-radius:8px; background:#f3f4f6; font-size:.8rem; display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; justify-content:space-between; }
    .partage--actif { background:#d8f3dc; color:#1b4332; }
    .partage__erreur { color:#b91c1c; width:100%; }
    .remise { margin-top:.75rem; display:grid; gap:.35rem; font-size:.85rem; }
    .remise input { width:7rem; padding:.5rem; border:1px solid #e5e7eb; border-radius:6px; font-size:1.1rem; letter-spacing:.3em; }
    .lien-itineraire { margin-top:.75rem; text-align:center; text-decoration:none; }
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

  /** Missions à accepter en premier, puis celles en cours. */
  acheminementsActifs = computed(() => this.reservations()
    .filter(r => ACHEMINEMENT_ACTIF.includes(r.statut))
    .sort((a, b) => Number(peut(b, 'accepter_mission')) - Number(peut(a, 'accepter_mission'))));
  livraisonsActives   = computed(() => this.livraisons()
    .filter(l => LIVRAISON_ACTIVE.includes(l.statut))
    .sort((a, b) => Number(peutFaire(b, 'accepter_mission')) - Number(peutFaire(a, 'accepter_mission'))));
  incidentPour   = signal<{ sujet_type: 'livraison' | 'reservation'; sujet_id: number; numero: string } | null>(null);
  erreurIncident = signal<string | null>(null);
  terminees = computed(() => [
    ...this.reservations().filter(r => !ACHEMINEMENT_ACTIF.includes(r.statut))
      .map(r => ({ cle: 'r' + r.id, numero: r.numero_reservation, libelle: `${r.produit} → ${r.entrepot?.nom_entrepot ?? ''}`, statut: r.statut })),
    ...this.livraisons().filter(l => !LIVRAISON_ACTIVE.includes(l.statut))
      .map(l => ({ cle: 'l' + l.id, numero: l.numero_livraison, libelle: `${l.commande?.produit ?? ''} → ${l.destination}`, statut: l.statut })),
  ]);

  private cleIncident = new CleSoumission();
  private minuteur?: ReturnType<typeof setInterval>;
  private readonly surRetourAuPremierPlan = () => { if (document.visibilityState === 'visible') this.charger(); };

  readonly partage = inject(PartagePositionService);

  constructor(private api: ApiService) {}

  partager(type: 'livraison' | 'reservation', id: number, numero: string): void { this.partage.demarrer({ type, id, numero }); }

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

  readonly peut = peut;

  libelle(a: ActionDepot): string { return LIBELLE_ACTION[a]; }

  actionsTransporteur(r: any): ActionDepot[] {
    return (['accepter_mission', 'refuser_mission', 'arriver_collecte', 'charger', 'partir', 'arriver', 'declarer_incident'] as ActionDepot[]).filter(a => peut(r, a));
  }

  versLeProducteur(r: any): boolean { return VERS_LE_PRODUCTEUR.includes(r.statut); }

  /** Lien de navigation (Google Maps) vers la prochaine adresse, si sa position est connue. */
  itineraire(r: any): string | null {
    const point = this.versLeProducteur(r) ? r.producteur : r.entrepot;
    if (point?.latitude == null || point?.longitude == null) return null;
    return `https://www.google.com/maps/dir/?api=1&destination=${point.latitude},${point.longitude}`;
  }

  agir(r: any, a: ActionDepot): void {
    const cle = 'r' + r.id;
    if (a === 'declarer_incident') { this.erreurIncident.set(null); this.incidentPour.set({ sujet_type: 'reservation', sujet_id: r.id, numero: r.numero_reservation }); return; }
    if (a === 'accepter_mission') {
      if (!confirm(`Accepter la mission ${r.numero_reservation} : ${r.produit} (${r.quantite_reservee} kg) à aller chercher chez le producteur ?`)) return;
      return this.executer(cle, this.api.accepterMission(r.id), "Mission acceptée. L'entrepôt et le producteur ont été prévenus.");
    }
    if (a === 'refuser_mission') {
      const motif = demanderMotif(`Refuser la mission ${r.numero_reservation} ?\nElle sera rendue à l'entrepôt, qui affectera un autre transporteur.`, true);
      if (motif === null) return;
      return this.executer(cle, this.api.refuserMission(r.id, motif), "Mission refusée. L'entrepôt a été prévenu.");
    }
    if (!confirm(CONFIRMATION_ETAPE[a] ?? 'Confirmer ?')) return;
    this.executer(cle, this.api.etapeReservation(r.id, ETAPE_DE_L_ACTION[a]!), 'Étape enregistrée.', () => {
      if (a === 'partir') this.partager('reservation', r.id, r.numero_reservation);
      if (a === 'arriver' && this.partage.partage('reservation', r.id)) this.partage.arreter();
    });
  }

  readonly peutFaire = peutFaire;
  readonly libelleIncident = libelleIncident;

  libelleLivraison(a: ActionLivraison): string { return LIBELLE_ACTION_LIVRAISON[a]; }

  /** Boutons de la carte (la remise par code a son propre champ). */
  actionsLivraison(l: any): ActionLivraison[] {
    return (['accepter_mission', 'refuser_mission', 'charger', 'partir', 'arriver', 'declarer_incident'] as ActionLivraison[]).filter(a => peutFaire(l, a));
  }

  typesIncident(sujet: 'livraison' | 'reservation') { return TYPES_INCIDENT[sujet]; }

  /** Avant le chargement, le transporteur se rend à l'entrepôt ; ensuite, chez l'acheteur. */
  versLEntrepot(l: any): boolean { return ['en_attente', 'prise_en_charge'].includes(l.statut); }

  itineraireLivraison(l: any): string | null {
    const e = l.commande?.stock?.entrepot;
    const a = l.commande?.acheteur;
    const [lat, lng] = this.versLEntrepot(l)
      ? [e?.latitude, e?.longitude]
      : [l.destination_latitude ?? a?.latitude, l.destination_longitude ?? a?.longitude];
    return lat == null || lng == null ? null : `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
  }

  agirLivraison(l: any, a: ActionLivraison): void {
    const cle = 'l' + l.id;
    if (a === 'declarer_incident') { this.erreurIncident.set(null); this.incidentPour.set({ sujet_type: 'livraison', sujet_id: l.id, numero: l.numero_livraison }); return; }
    if (a === 'accepter_mission') {
      if (!confirm(`Accepter la livraison ${l.numero_livraison} : ${l.commande?.produit} (${l.commande?.quantite} kg) à charger à l'entrepôt et livrer à ${l.destination} ?`)) return;
      return this.executer(cle, this.api.accepterMissionLivraison(l.id), "Mission acceptée. L'entrepôt a été prévenu.");
    }
    if (a === 'refuser_mission') {
      const motif = demanderMotif(`Refuser la livraison ${l.numero_livraison} ?\nElle sera rendue à l'entrepôt, qui affectera un autre transporteur.`, true);
      if (motif === null) return;
      return this.executer(cle, this.api.refuserMissionLivraison(l.id, motif), "Mission refusée. L'entrepôt a été prévenu.");
    }
    if (!confirm(CONFIRMATION_ETAPE_LIVRAISON[a] ?? 'Confirmer ?')) return;
    this.executer(cle, this.api.etapeLivraison(l.id, ETAPE_LIVRAISON[a]!), 'Étape enregistrée.', () => {
      if (a === 'partir') this.partager('livraison', l.id, l.numero_livraison);
      if (a === 'arriver' && this.partage.partage('livraison', l.id)) this.partage.arreter();
    });
  }

  remettre(l: any, code: string): void {
    const saisi = (code ?? '').trim();
    if (!/^\d{4}$/.test(saisi)) { this.erreurAction.set('Le code de réception comporte 4 chiffres.'); return; }
    this.executer('l' + l.id, this.api.remettreLivraison(l.id, saisi), "Livraison remise à l'acheteur. Mission terminée.");
  }

  declarer(cible: { sujet_type: 'livraison' | 'reservation'; sujet_id: number }, saisie: { type: string; description: string }): void {
    this.actionEnCours.set('incident');
    this.erreurIncident.set(null);
    this.api.declarerIncident({ sujet_type: cible.sujet_type, sujet_id: cible.sujet_id, ...saisie }, this.cleIncident.valeur).subscribe({
      next: () => {
        this.cleIncident.renouveler();
        this.actionEnCours.set(null);
        this.incidentPour.set(null);
        this.message.set("Incident déclaré. L'entrepôt a été prévenu.");
        setTimeout(() => this.message.set(null), 4000);
        this.charger();
      },
      error: err => {
        this.actionEnCours.set(null);
        this.erreurIncident.set(err.status === 0 ? 'Serveur injoignable : incident non envoyé, réessayez.' : err.error?.message ?? 'Déclaration impossible.');
      },
    });
  }

  private executer(cle: string, action: ReturnType<ApiService['getReservations']>, succes: string, apres?: () => void): void {
    this.actionEnCours.set(cle);
    this.erreurAction.set(null);
    action.subscribe({
      next: () => {
        this.actionEnCours.set(null);
        apres?.();
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
