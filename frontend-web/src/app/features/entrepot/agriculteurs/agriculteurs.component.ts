import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';

/**
 * Agriculteurs de l'entrepôt (lot 11) : recherche par nom, téléphone ou région,
 * puis une fiche qui regroupe ses dépôts, son stock ici et ses ventes.
 */
@Component({
  selector: 'app-agriculteurs',
  standalone: true,
  imports: [DecimalPipe, LibelleStatutPipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Agriculteurs</h1></div>
      <input type="search" class="recherche" placeholder="Nom, téléphone ou région…" aria-label="Rechercher un agriculteur"
        [value]="q()" (input)="chercher($event)" />

      <div class="grille">
        <ul class="liste card">
          @for (p of liste(); track p.id) {
            <li><button type="button" [class.actif]="fiche()?.producteur?.id === p.id" (click)="ouvrir(p.id)">
              <strong>{{ p.nom }}</strong> <small>{{ p.telephone }}{{ p.region ? ' · ' + p.region : '' }}</small>
              <small>{{ p.stock_kg | number }} kg en stock · {{ p.depots_en_cours }} dépôt(s) en cours</small>
            </button></li>
          } @empty { <li class="vide">{{ chargement() ? 'Chargement…' : 'Aucun agriculteur trouvé.' }}</li> }
        </ul>

        @if (fiche(); as f) {
          <section class="card fiche" aria-label="Fiche de l'agriculteur">
            <h2>{{ f.producteur.nom }}</h2>
            <p>📞 <a [href]="'tel:' + f.producteur.telephone">{{ f.producteur.telephone }}</a>{{ f.producteur.region ? ' · ' + f.producteur.region : '' }}</p>
            <h3>Stock ici</h3>
            @for (s of f.stocks; track s.id) { <p>{{ s.produit }} — {{ s.quantite_disponible | number }} kg disponibles (lot {{ s.lot }})</p> } @empty { <p class="vide">Aucun stock.</p> }
            <h3>Dépôts</h3>
            @for (d of f.depots; track d.id) { <p>{{ d.numero_reservation }} · {{ d.produit }}, {{ d.quantite_reservee | number }} kg · {{ d.statut | libelleStatut }}</p> }
            <h3>Ventes</h3>
            @for (v of f.ventes; track v.id) { <p>{{ v.numero_commande }} · {{ v.quantite | number }} kg · {{ v.prix | number }} FCFA · {{ v.statut | libelleStatut }}</p> } @empty { <p class="vide">Aucune vente.</p> }
          </section>
        }
      </div>
    </div>
  `,
  styles: [`
    .recherche { width:100%; min-height:48px; font-size:1.05rem; margin-bottom:1rem; }
    .grille { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; align-items:start; }
    .liste { list-style:none; margin:0; padding:.4rem; }
    .liste button { display:grid; gap:.15rem; width:100%; text-align:left; background:none; border:none; padding:.7rem; border-radius:8px; cursor:pointer; }
    .liste button:hover, .liste button.actif { background:#f0fdf4; }
    .liste small, .vide { color:#6b7280; }
    .fiche h3 { margin:1rem 0 .3rem; font-size:1rem; }
    .fiche p { margin:.2rem 0; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class AgriculteursComponent implements OnInit {
  private readonly api = inject(ApiService);
  q = signal('');
  liste = signal<any[]>([]);
  fiche = signal<any>(null);
  chargement = signal(true);
  private minuteur: ReturnType<typeof setTimeout> | null = null;

  ngOnInit(): void { this.charger(); }

  chercher(e: Event): void {
    this.q.set((e.target as HTMLInputElement).value);
    if (this.minuteur) clearTimeout(this.minuteur);
    this.minuteur = setTimeout(() => this.charger(), 250);
  }

  charger(): void {
    this.chargement.set(true);
    this.api.getAgriculteurs(this.q().trim()).subscribe({
      next: res => { this.liste.set(res.producteurs ?? []); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  ouvrir(id: number): void { this.api.getAgriculteur(id).subscribe({ next: res => this.fiche.set(res) }); }
}
