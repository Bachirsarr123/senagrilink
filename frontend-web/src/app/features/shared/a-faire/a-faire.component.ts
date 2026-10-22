import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { ACTIONS_CONDUITE, executerActionDirecte, libelleActionDirecte } from '../../../shared/action-directe';

/** Page d'accueil de chaque rôle : ce qui attend une action de sa part, calculé par le serveur. */
@Component({
  selector: 'app-a-faire',
  standalone: true,
  imports: [DatePipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>À faire</h1>
        <span class="subtitle">
          @if (chargement()) { Chargement… }
          @else if (elements().length === 0) { Rien en attente de votre part }
          @else { {{ elements().length }} action(s) en attente@if (enRetard()) { · <strong class="retard">{{ enRetard() }} en retard</strong> } }
        </span>
      </div>

      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }
      @if (erreur()) {
        <div class="alert alert-error" role="alert">{{ erreur() }} <button class="btn-secondary btn-sm" (click)="charger()">Réessayer</button></div>
      }

      @if (!chargement() && !erreur() && elements().length === 0) {
        <div class="card vide">
          <p>✅ Tout est à jour. Les nouvelles demandes apparaîtront ici.</p>
          <div class="btn-group">
            @for (r of raccourcis(); track r.lien) { <a class="btn-secondary" [routerLink]="r.lien">{{ r.libelle }}</a> }
          </div>
        </div>
      }

      <ul class="liste">
        @for (e of elements(); track e.categorie + e.sujet_id + (e.incident_id ?? '')) {
          <li class="element" [class.element--urgent]="e.urgence === 'haute'">
            <a [routerLink]="lien(e)" class="element__lien">
              <span class="element__icone" aria-hidden="true">{{ icone(e.categorie) }}</span>
              <span class="element__corps">
                <strong>{{ e.etape }}</strong>
                <span class="element__titre">{{ e.titre }}</span>
                <span class="element__meta"><code class="code-lot">{{ e.numero }}</code>
                  @if (e.depuis) { · depuis le {{ e.depuis | date:'dd/MM à HH:mm' }} }
                  @if (e.en_retard) { · <span class="retard">en retard</span> }</span>
              </span>
              <span class="element__fleche" aria-hidden="true">›</span>
            </a>
            <!-- Lot 9 : l'action principale d'un appui, quelques secondes pour annuler -->
            @if (e.action) {
              <div class="element__action">
                @if (attente()?.cle === cle(e)) {
                  <span class="attente">« {{ libelle(e) }} » dans {{ attente()!.reste }} s</span>
                  <button type="button" class="btn-secondary btn-sm" (click)="annuler()">Annuler</button>
                } @else if (conduite(e)) {
                  <a class="btn-primary btn-full" [routerLink]="['/conduite', e.sujet_type, e.sujet_id]">🧭 Conduire</a>
                } @else if (choixManuel(e)) {
                  <a class="btn-primary btn-full" [routerLink]="lien(e)">{{ libelle(e) }}</a>
                } @else {
                  <button type="button" class="btn-primary btn-full" [disabled]="envoi() === cle(e)" (click)="agir(e)">{{ envoi() === cle(e) ? 'Envoi…' : libelle(e) }}</button>
                }
              </div>
            }
          </li>
        }
      </ul>
    </div>
  `,
  styles: [`
    .liste { list-style:none; margin:0; padding:0; display:grid; gap:.6rem; }
    .element { background:#fff; border:1px solid #e5e7eb; border-radius:10px; }
    .element--urgent { border-left:4px solid #dc2626; }
    .element__lien { display:flex; gap:.8rem; align-items:center; padding:.8rem 1rem; color:inherit; text-decoration:none; }
    .element__lien:hover { background:#f9fafb; }
    .element__icone { font-size:1.4rem; }
    .element__corps { display:grid; gap:.15rem; flex:1; min-width:0; }
    .element__titre { color:#374151; font-size:.9rem; overflow-wrap:anywhere; }
    .element__meta { color:#6b7280; font-size:.8rem; }
    .element__fleche { font-size:1.6rem; color:#9ca3af; }
    .element__action { padding:0 1rem .8rem; display:flex; gap:.6rem; align-items:center; }
    .element__action .btn-full { min-height:48px; font-size:1.05rem; font-weight:600; text-align:center; }
    .attente { flex:1; font-weight:600; background:#fef3c7; padding:.6rem; border-radius:8px; }
    .retard { color:#b91c1c; }
    .vide p { margin:0 0 .75rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class AFaireComponent implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private router = inject(Router);

  elements = signal<any[]>([]);
  chargement = signal(true);
  erreur = signal<string | null>(null);
  enRetard = computed(() => this.elements().filter(e => e.en_retard).length);

  ngOnInit(): void { this.charger(); }

  /** Délai pour annuler un appui par erreur (ms), réduit en test. */
  static delaiAnnulationMs = 4000;
  attente = signal<{ cle: string; reste: number } | null>(null);
  envoi = signal<string | null>(null);
  message = signal<string | null>(null);
  private minuteur: ReturnType<typeof setInterval> | null = null;

  cle(e: any): string { return `${e.sujet_type}-${e.sujet_id}`; }
  conduite(e: any): boolean { return ACTIONS_CONDUITE.includes(e.action); }
  choixManuel(e: any): boolean { return ['assigner', 'organiser_livraison'].includes(e.action) && !e.transporteur_propose; }

  libelle(e: any): string {
    const t = e.transporteur_propose;
    const nom = t ? `${t.nom}${t.distance_km != null ? ` (à ${String(t.distance_km).replace('.', ',')} km)` : ''}` : null;
    return libelleActionDirecte(e.action, e.sujet_type, nom);
  }

  agir(e: any): void {
    this.annuler();
    const fin = Date.now() + AFaireComponent.delaiAnnulationMs;
    this.attente.set({ cle: this.cle(e), reste: Math.ceil(AFaireComponent.delaiAnnulationMs / 1000) });
    this.minuteur = setInterval(() => {
      const reste = fin - Date.now();
      if (reste > 0) { this.attente.set({ cle: this.cle(e), reste: Math.ceil(reste / 1000) }); return; }
      this.annuler();
      this.envoi.set(this.cle(e));
      executerActionDirecte(this.api, e.sujet_type, e.sujet_id, e.action, e.transporteur_propose?.id).subscribe({
        next: res => {
          this.envoi.set(null);
          this.message.set(res?.message ?? "C'est fait.");
          if (e.action === 'accepter_mission') { this.router.navigate(['/conduite', e.sujet_type, e.sujet_id]); return; }
          this.charger();
        },
        error: err => { this.envoi.set(null); this.erreur.set(err.error?.message ?? 'Action impossible.'); this.charger(); },
      });
    }, Math.min(250, AFaireComponent.delaiAnnulationMs));
  }

  annuler(): void { if (this.minuteur) clearInterval(this.minuteur); this.minuteur = null; this.attente.set(null); }

  charger(): void {
    this.erreur.set(null);
    this.api.getAFaire().subscribe({
      next: res => { this.elements.set(res.elements ?? []); this.chargement.set(false); },
      error: err => {
        this.chargement.set(false);
        this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger vos actions.');
      },
    });
  }

  icone(c: string): string {
    return ({ depot: '🌾', commande: '🧾', livraison: '🚚', incident: '⚠️', stock: '📦', lieu: '📍' } as Record<string, string>)[c] ?? '•';
  }

  /** Page où l'action se fait. */
  lien(e: any): any[] {
    const role = this.auth.role();
    switch (e.categorie) {
      case 'commande': return ['/commandes', e.sujet_id];
      case 'incident': return ['/entrepot/incidents'];
      case 'stock':    return ['/entrepot/alertes'];
      case 'lieu':     return ['/profil'];
      case 'livraison': return ['/transporteur/missions'];
      default: return [role === 'producteur' ? '/producteur/reservations' : role === 'transporteur' ? '/transporteur/missions' : '/entrepot/reservations'];
    }
  }

  raccourcis(): { libelle: string; lien: string }[] {
    return ({
      producteur: [{ libelle: 'Mes productions', lien: '/producteur/productions' }, { libelle: 'Déposer ma récolte', lien: '/producteur/reservations' }],
      gestionnaire_entrepot: [{ libelle: 'Stocks', lien: '/entrepot/stocks' }, { libelle: 'Commandes', lien: '/entrepot/commandes' }],
      acheteur_gros: [{ libelle: 'Catalogue', lien: '/acheteur/catalogue' }, { libelle: 'Mes commandes', lien: '/acheteur/mes-commandes' }],
      transporteur: [{ libelle: 'Mes missions', lien: '/transporteur/missions' }],
    } as Record<string, { libelle: string; lien: string }[]>)[this.auth.role() ?? ''] ?? [];
  }
}
