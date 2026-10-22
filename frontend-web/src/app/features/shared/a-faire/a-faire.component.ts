import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';

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
    .retard { color:#b91c1c; }
    .vide p { margin:0 0 .75rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class AFaireComponent implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);

  elements = signal<any[]>([]);
  chargement = signal(true);
  erreur = signal<string | null>(null);
  enRetard = computed(() => this.elements().filter(e => e.en_retard).length);

  ngOnInit(): void { this.charger(); }

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
