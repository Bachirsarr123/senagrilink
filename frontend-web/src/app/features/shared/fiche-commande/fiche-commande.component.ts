import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Observable } from 'rxjs';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { demanderMotif } from '../../../shared/confirmation/confirmation';
import { ActionCommande, LIBELLE_ACTION_COMMANDE } from '../../../shared/livraison/livraison';

/** Actions faites directement depuis la fiche ; les autres renvoient vers la page dédiée. */
const DIRECTES: ActionCommande[] = ['confirmer', 'preparer', 'marquer_prete', 'confirmer_reception', 'demander_annulation'];

/**
 * Fiche d'une commande (lot 5) : la même page pour tous les acteurs —
 * état, prochaine étape, étapes datées avec leur auteur, livraison,
 * incidents —, chacun avec ses boutons. Le serveur retire ce qui ne regarde
 * pas le lecteur (prix pour le transporteur, code de réception hors acheteur).
 */
@Component({
  selector: 'app-fiche-commande',
  standalone: true,
  imports: [DatePipe, DecimalPipe, RouterLink, LibelleStatutPipe],
  template: `
    <div class="page">
      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }
      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }

      @if (fiche(); as f) {
        <div class="page-header">
          <h1>Commande <code class="code-lot">{{ f.commande.numero_commande }}</code></h1>
          <span class="badge badge-{{ f.commande.statut }}">{{ f.commande.statut | libelleStatut }}</span>
        </div>

        <div class="grille">
          <div class="card">
            <h2>{{ f.commande.produit }} — {{ f.commande.quantite | number }} kg</h2>
            @if (f.prochaine_etape) { <p class="prochaine">➡️ {{ f.prochaine_etape.libelle }}</p> }
            @if (f.code_reception) {
              <p class="code-reception">Code de réception à donner au transporteur : <strong>{{ f.code_reception }}</strong></p>
            }
            <dl class="details">
              @if (f.commande.prix != null) { <div><dt>Montant</dt><dd>{{ f.commande.prix | number:'1.0-2' }} FCFA ({{ f.commande.prix_unitaire | number }} FCFA / kg)</dd></div> }
              <div><dt>Commandée le</dt><dd>{{ f.commande.date_commande | date:'dd/MM/yyyy à HH:mm' }}</dd></div>
              @if (f.commande.panier) { <div><dt>Panier</dt><dd>{{ f.commande.panier }}</dd></div> }
              @if (f.lot.code_tracabilite) { <div><dt>Lot</dt><dd><a [routerLink]="['/tracabilite']" [queryParams]="{ code: f.lot.code_tracabilite }">{{ f.lot.code_tracabilite }}</a></dd></div> }
              @if (f.lot.entrepot) { <div><dt>Entrepôt</dt><dd>{{ f.lot.entrepot }}@if (f.lot.telephone_entrepot) { · <a [href]="'tel:' + f.lot.telephone_entrepot">{{ f.lot.telephone_entrepot }}</a> }</dd></div> }
              @if (f.acheteur && f.role !== 'acheteur') { <div><dt>Acheteur</dt><dd>{{ f.acheteur.nom }}@if (f.acheteur.telephone) { · <a [href]="'tel:' + f.acheteur.telephone">{{ f.acheteur.telephone }}</a> }@if (f.acheteur.adresse) { <br><small>{{ f.acheteur.adresse }}</small> }</dd></div> }
            </dl>
            @if (boutons().length || renvoi()) {
              <div class="btn-group">
                @for (a of boutons(); track a) {
                  <button [class]="a === 'demander_annulation' ? 'btn-ghost' : 'btn-success'" [disabled]="envoi()" (click)="agir(a)">{{ libelle(a) }}</button>
                }
                @if (renvoi(); as r) { <a class="btn-secondary" [routerLink]="r.lien">{{ r.libelle }}</a> }
              </div>
            }
          </div>

          @if (f.livraison; as l) {
            <div class="card">
              <h2>Livraison <code class="code-lot">{{ l.numero_livraison }}</code></h2>
              <p><span class="badge badge-{{ l.statut }}">{{ l.statut | libelleStatut }}</span></p>
              <dl class="details">
                @if (l.transporteur) { <div><dt>Transporteur</dt><dd>{{ l.transporteur.nom }}@if (l.transporteur.telephone) { · <a [href]="'tel:' + l.transporteur.telephone">{{ l.transporteur.telephone }}</a> }</dd></div> }
                @if (l.destination) { <div><dt>Destination</dt><dd>{{ l.destination }}</dd></div> }
                @if (l.parti_le) { <div><dt>Parti le</dt><dd>{{ l.parti_le | date:'dd/MM/yyyy à HH:mm' }}</dd></div> }
                @if (l.arrivee_estimee_le) { <div><dt>Arrivée estimée</dt><dd>{{ l.arrivee_estimee_le | date:'HH:mm' }}</dd></div> }
                @if (l.date_livraison) { <div><dt>Remise le</dt><dd>{{ l.date_livraison | date:'dd/MM/yyyy à HH:mm' }}</dd></div> }
              </dl>
              @if (l.suivi_disponible) { <a class="btn-primary" [routerLink]="['/suivi', 'livraison', l.id]">📍 Suivre sur la carte</a> }
            </div>
          }
        </div>

        @if (f.incidents.length) {
          <div class="card">
            <h2>Incidents</h2>
            <ul class="incidents">
              @for (i of f.incidents; track i.id) {
                <li><strong>{{ i.libelle }}</strong> — {{ i.description }} <span class="badge badge-{{ i.statut }}">{{ i.statut | libelleStatut }}</span>
                  @if (i.decision) { <br><small>Décision : {{ i.decision }}@if (i.motif_decision) { ({{ i.motif_decision }}) }</small> }</li>
              }
            </ul>
          </div>
        }

        <div class="card">
          <h2>Étapes</h2>
          <ol class="etapes">
            @for (e of f.etapes; track $index) {
              <li><span class="etapes__date">{{ e.date | date:'dd/MM/yyyy HH:mm' }}</span>
                <span><strong>{{ e.libelle }}</strong>@if (e.sujet === 'livraison') { <small> (livraison)</small> } — {{ e.auteur }}@if (e.role) { <small> · {{ e.role | libelleStatut }}</small> }
                  @if (e.motif) { <br><small>Motif : {{ e.motif }}</small> }</span></li>
            }
          </ol>
        </div>
      } @else if (!erreur()) {
        <p class="loading">Chargement de la commande…</p>
      }
    </div>
  `,
  styles: [`
    .grille { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); }
    .prochaine { font-weight:600; margin:.25rem 0 .75rem; }
    .code-reception { padding:.5rem .75rem; border-radius:8px; background:#fef3c7; }
    .code-reception strong { font-size:1.2rem; letter-spacing:.2em; }
    .details { display:grid; gap:.4rem; margin:0 0 1rem; }
    .details div { display:flex; gap:.75rem; flex-wrap:wrap; }
    .details dt { min-width:7.5rem; color:#6b7280; font-size:.85rem; }
    .details dd { margin:0; }
    .etapes { margin:0; padding-left:1.2rem; display:grid; gap:.5rem; }
    .etapes li { display:flex; gap:.75rem; flex-wrap:wrap; }
    .etapes__date { color:#6b7280; font-size:.85rem; min-width:8.5rem; }
    .incidents { margin:0; padding-left:1.1rem; display:grid; gap:.4rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class FicheCommandeComponent implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);

  fiche = signal<any | null>(null);
  erreur = signal<string | null>(null);
  message = signal<string | null>(null);
  envoi = signal(false);
  private id = 0;

  ngOnInit(): void {
    this.id = Number(this.route.snapshot.paramMap.get('id'));
    this.charger();
  }

  charger(): void {
    this.api.getFicheCommande(this.id).subscribe({
      next: f => { this.fiche.set(f); this.erreur.set(null); },
      error: err => this.erreur.set(err.status === 403 ? 'Vous n\'avez pas accès à cette commande.'
        : err.status === 404 ? 'Commande introuvable.' : err.status === 0 ? 'Serveur injoignable.' : err.error?.message ?? 'Chargement impossible.'),
    });
  }

  boutons(): ActionCommande[] { return DIRECTES.filter(a => this.fiche()?.actions?.includes(a)); }

  /** Lien vers la page où se font les autres actions (organiser, incident, remise…). */
  renvoi(): { libelle: string; lien: string } | null {
    const f = this.fiche();
    if (!f) return null;
    const autres = (f.actions as string[]).filter(a => !DIRECTES.includes(a as ActionCommande));
    if (f.role === 'transporteur' && f.actions_livraison?.length) return { libelle: 'Ouvrir mes missions', lien: '/transporteur/missions' };
    if (!autres.length) return null;
    return f.role === 'acheteur' ? { libelle: 'Ouvrir mes commandes', lien: '/acheteur/mes-commandes' }
      : f.role === 'gestionnaire' || f.role === 'administrateur' ? { libelle: 'Ouvrir les commandes de l\'entrepôt', lien: '/entrepot/commandes' } : null;
  }

  libelle(a: ActionCommande): string { return LIBELLE_ACTION_COMMANDE[a]; }

  agir(a: ActionCommande): void {
    const c = this.fiche()!.commande;
    let requete: Observable<any>;
    switch (a) {
      case 'confirmer':
        if (!confirm(`Confirmer la commande ${c.numero_commande} ? ${c.quantite} kg seront réservés.`)) return;
        requete = this.api.confirmerCommande(c.id); break;
      case 'preparer': requete = this.api.preparerCommande(c.id); break;
      case 'marquer_prete': requete = this.api.marquerCommandePrete(c.id); break;
      case 'confirmer_reception':
        if (!confirm(`Confirmer que vous avez bien reçu ${c.quantite} kg de ${c.produit} ?`)) return;
        requete = this.api.confirmerReception(c.id); break;
      default: {
        const motif = demanderMotif(`Demander l'annulation de ${c.numero_commande} ?\nL'entrepôt décide.`, false);
        if (motif === null) return;
        requete = this.api.demanderAnnulationCommande(c.id, motif || undefined);
      }
    }
    this.envoi.set(true);
    requete.subscribe({
      next: res => { this.envoi.set(false); this.message.set(res?.message ?? 'Enregistré.'); this.charger(); },
      error: err => { this.envoi.set(false); this.erreur.set(err.error?.message ?? 'Action impossible.'); this.charger(); },
    });
  }
}
