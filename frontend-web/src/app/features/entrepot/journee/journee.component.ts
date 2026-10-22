import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { executerActionDirecte, libelleActionDirecte } from '../../../shared/action-directe';
import { AFaireComponent } from '../../shared/a-faire/a-faire.component';

/** Rafraîchissement de la journée (les missions avancent seules). */
const ACTUALISATION_MS = 30_000;

/**
 * « Ma journée » du gestionnaire (lot 11) : en tête ce qui demande son
 * attention, puis ce qui arrive, ce qui est à préparer et ce qui part. Le
 * pilote automatique fait le reste ; ses réglages sont ici, en un appui.
 */
@Component({
  selector: 'app-journee',
  standalone: true,
  imports: [AFaireComponent, DatePipe, DecimalPipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Ma journée</h1>
        <a class="btn-secondary" routerLink="/entrepot/agriculteurs">🔎 Agriculteurs</a>
      </div>

      <section class="card pilote" aria-label="Pilote automatique">
        <strong>🤖 Pilote automatique</strong>
        <label><input type="checkbox" [checked]="pilote()?.auto_accepter_depots" (change)="regler('auto_accepter_depots', $event)" /> Accepter les dépôts</label>
        <label><input type="checkbox" [checked]="pilote()?.auto_confirmer_commandes" (change)="regler('auto_confirmer_commandes', $event)" /> Confirmer les commandes</label>
        <label><input type="checkbox" [checked]="pilote()?.auto_affecter_transporteurs" (change)="regler('auto_affecter_transporteurs', $event)" /> Trouver les transporteurs</label>
        <label>Délai de réponse
          <select [value]="pilote()?.delai_reponse_min ?? 10" (change)="reglerDelai($event)">
            @for (m of [5, 10, 15, 30]; track m) { <option [value]="m">{{ m }} min</option> }
          </select></label>
      </section>

      <section aria-label="À traiter">
        <h2>⚠️ À traiter</h2>
        <app-a-faire [integre]="true" />
      </section>

      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }
      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }

      <div class="colonnes">
        <section class="card colonne" aria-label="Arrivent">
          <h2>🌾 Arrivent <span class="compte">{{ journee()?.arrivent?.length ?? 0 }}</span></h2>
          @for (a of journee()?.arrivent ?? []; track a.id) {
            <div class="ligne" [class.ligne--urgent]="a.a_receptionner">
              <strong>{{ a.producteur?.nom }}</strong> — {{ a.produit }}, {{ a.quantite | number }} kg
              <small>{{ a.etape }}@if (a.arrivee_estimee_le) { · arrivée vers {{ a.arrivee_estimee_le | date:'HH:mm' }} }</small>
              <small>{{ a.livre_lui_meme ? 'Livré par le producteur' : (a.transporteur ? '🚚 ' + a.transporteur.nom : 'Transporteur en cours de recherche') }}</small>
              @if (a.a_receptionner) { <a class="btn-primary btn-sm" routerLink="/entrepot/reservations">Réceptionner</a> }
            </div>
          } @empty { <p class="vide">Aucune arrivée prévue.</p> }
        </section>

        <section class="card colonne" aria-label="À préparer">
          <h2>📦 À préparer <span class="compte">{{ journee()?.a_preparer?.length ?? 0 }}</span></h2>
          @for (c of journee()?.a_preparer ?? []; track c.id) {
            <div class="ligne">
              <strong>{{ c.produit }}, {{ c.quantite | number }} kg</strong> pour {{ c.acheteur?.nom }}
              <small><a [routerLink]="['/commandes', c.id]">{{ c.numero }}</a></small>
              <button type="button" class="btn-primary gros" [disabled]="envoi() === c.id" (click)="agir(c)">{{ envoi() === c.id ? 'Envoi…' : libelle(c.action) }}</button>
            </div>
          } @empty { <p class="vide">Rien à préparer.</p> }
        </section>

        <section class="card colonne" aria-label="Partent">
          <h2>🚚 Partent <span class="compte">{{ journee()?.partent?.length ?? 0 }}</span></h2>
          @for (l of journee()?.partent ?? []; track l.id) {
            <div class="ligne" [class.ligne--urgent]="l.sans_transporteur">
              <strong>{{ l.produit }}, {{ l.quantite | number }} kg</strong> pour {{ l.acheteur?.nom }}
              <small>{{ l.etape }}@if (l.arrivee_estimee_le) { · arrivée vers {{ l.arrivee_estimee_le | date:'HH:mm' }} }</small>
              <small>{{ l.transporteur ? '🚚 ' + l.transporteur.nom + (l.mission_acceptee ? '' : ' (doit accepter)') : 'Aucun transporteur' }}</small>
              @if (l.sans_transporteur) { <a class="btn-primary btn-sm" [routerLink]="['/commandes', l.commande_id]">Choisir un transporteur</a> }
            </div>
          } @empty { <p class="vide">Aucun départ en cours.</p> }
        </section>
      </div>
    </div>
  `,
  styles: [`
    .page-header { display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; }
    .pilote { display:flex; flex-wrap:wrap; gap:.6rem 1.2rem; align-items:center; padding:.8rem 1rem; margin-bottom:1rem; }
    .pilote label { display:flex; gap:.4rem; align-items:center; font-size:.95rem; }
    .pilote input[type=checkbox] { width:20px; height:20px; }
    h2 { font-size:1.1rem; margin:1rem 0 .6rem; }
    .colonnes { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-top:1rem; }
    .colonne h2 { margin-top:0; }
    .compte { background:#e5e7eb; border-radius:999px; padding:0 .55rem; font-size:.85rem; }
    .ligne { display:grid; gap:.2rem; padding:.6rem 0; border-top:1px solid #f3f4f6; }
    .ligne--urgent { border-left:4px solid #dc2626; padding-left:.6rem; }
    .ligne small { color:#6b7280; }
    .gros { min-height:46px; font-weight:700; margin-top:.3rem; }
    .vide { color:#6b7280; margin:0; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class JourneeComponent implements OnInit, OnDestroy {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  journee = signal<any>(null);
  pilote = signal<any>(null);
  erreur = signal<string | null>(null);
  message = signal<string | null>(null);
  envoi = signal<number | null>(null);
  private minuteur: ReturnType<typeof setInterval> | null = null;

  ngOnInit(): void {
    this.charger();
    this.minuteur = setInterval(() => this.charger(), ACTUALISATION_MS);
  }

  ngOnDestroy(): void { if (this.minuteur) clearInterval(this.minuteur); }

  charger(): void {
    this.api.getJournee().subscribe({
      next: res => { this.journee.set(res); this.pilote.set(res.pilote); this.erreur.set(null); },
      error: err => this.erreur.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : err.error?.message ?? 'Impossible de charger la journée.'),
    });
  }

  libelle(action: string): string { return libelleActionDirecte(action, 'commande'); }

  agir(c: any): void {
    this.envoi.set(c.id);
    executerActionDirecte(this.api, 'commande', c.id, c.action, undefined, c.statut).subscribe({
      next: res => { this.envoi.set(null); this.message.set(res?.message ?? "C'est fait."); this.charger(); },
      error: err => { this.envoi.set(null); this.erreur.set(err.error?.message ?? 'Action impossible.'); this.charger(); },
    });
  }

  regler(regle: string, evenement: Event): void {
    this.enregistrer({ [regle]: (evenement.target as HTMLInputElement).checked });
  }

  reglerDelai(evenement: Event): void {
    this.enregistrer({ delai_reponse_min: Number((evenement.target as HTMLSelectElement).value) });
  }

  private enregistrer(reglage: Record<string, unknown>): void {
    this.pilote.update(p => ({ ...p, ...reglage }));
    this.api.updateProfile(reglage).subscribe({
      next: res => {
        this.message.set('Réglage du pilote automatique enregistré.');
        if (res?.utilisateur) this.auth.mettreAJourUtilisateur(res.utilisateur);
      },
      error: err => { this.erreur.set(err.error?.message ?? 'Réglage non enregistré.'); this.charger(); },
    });
  }
}
