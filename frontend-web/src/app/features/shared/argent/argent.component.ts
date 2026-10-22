import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { BENEFICIAIRES, ETATS_VERSEMENT, MOYENS } from '../../../shared/paiement/paiement';

/**
 * « Mon argent » (lot 12), pour le producteur, l'entrepôt et le transporteur :
 * ce qui est mis de côté (commande payée, pas encore reçue), ce qui est versé,
 * ce qui est à recevoir en main propre (paiement en espèces), et le détail de
 * chaque vente. On y règle aussi le numéro Wave ou Orange Money où recevoir l'argent.
 */
@Component({
  selector: 'app-argent',
  standalone: true,
  imports: [DatePipe, DecimalPipe, FormsModule],
  template: `
    <div class="page">
      <div class="page-header"><h1>Mon argent</h1></div>
      @if (message()) { <div class="alert alert-success" role="status">{{ message() }}</div> }
      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }

      @if (donnees(); as d) {
        <div class="totaux">
          <div class="card total"><span>Mis de côté</span><strong>{{ d.totaux.mis_de_cote | number:'1.0-0' }} FCFA</strong><small>versé à la réception</small></div>
          <div class="card total total--vert"><span>Déjà versé</span><strong>{{ d.totaux.verse | number:'1.0-0' }} FCFA</strong><small>sur votre {{ moyen(d.moyen_paiement) }}</small></div>
          @if (d.totaux.a_regler > 0) {
            <div class="card total total--orange"><span>À recevoir en main propre</span><strong>{{ d.totaux.a_regler | number:'1.0-0' }} FCFA</strong><small>payé en espèces au transporteur</small></div>
          }
        </div>

        <div class="card reception">
          <strong>Recevoir mon argent sur</strong>
          <select [(ngModel)]="moyenChoisi" aria-label="Moyen de paiement"><option value="wave">Wave</option><option value="orange_money">Orange Money</option></select>
          <input type="tel" [(ngModel)]="numero" placeholder="77 123 45 67" aria-label="Numéro de réception" />
          <button class="btn-primary" (click)="enregistrer()">Enregistrer</button>
        </div>

        <div class="card">
          <h2>Détail</h2>
          @for (v of d.versements; track v.id) {
            <div class="ligne">
              <div><strong>{{ beneficiaire(v.beneficiaire) }}</strong> — {{ v.commande?.numero_commande }} · {{ v.commande?.produit }}, {{ v.commande?.quantite | number }} kg
                @if (v.beneficiaire === 'producteur') {
                  <small>{{ v.commande?.prix | number:'1.0-0' }} FCFA − entrepôt {{ v.commande?.frais_stockage | number:'1.0-0' }} − commission {{ v.commande?.commission | number:'1.0-0' }}</small>
                }
              </div>
              <div class="droite"><strong>{{ v.montant | number:'1.0-0' }} FCFA</strong>
                <span class="badge badge-versement-{{ v.statut }}">{{ etat(v.statut) }}</span>
                @if (v.verse_le) { <small>{{ v.verse_le | date:'dd/MM à HH:mm' }}{{ v.telephone ? ' · ' + v.telephone : '' }}</small> }
              </div>
            </div>
          } @empty { <p class="vide">Aucun mouvement pour l'instant.</p> }
        </div>
      } @else { <p class="loading">Chargement…</p> }
    </div>
  `,
  styles: [`
    .totaux { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1rem; }
    .total { display:grid; gap:.2rem; }
    .total strong { font-size:1.6rem; }
    .total small, .ligne small, .vide { color:#6b7280; }
    .total--vert strong { color:#166534; }
    .total--orange strong { color:#b45309; }
    .reception { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; margin-bottom:1rem; }
    .reception input { max-width:180px; }
    .ligne { display:flex; justify-content:space-between; gap:1rem; padding:.6rem 0; border-top:1px solid #f3f4f6; flex-wrap:wrap; }
    .ligne small { display:block; }
    .droite { text-align:right; display:grid; gap:.2rem; justify-items:end; }
    .badge-versement-bloque, .badge-versement-a_regler { background:#fef3c7; color:#92400e; }
    .badge-versement-verse, .badge-versement-regle { background:#dcfce7; color:#166534; }
    .badge-versement-annule { background:#e5e7eb; color:#374151; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class ArgentComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  donnees = signal<any>(null);
  message = signal<string | null>(null);
  erreur = signal<string | null>(null);
  moyenChoisi = 'wave';
  numero = '';

  ngOnInit(): void { this.charger(); }

  charger(): void {
    this.api.getArgent().subscribe({
      next: res => { this.donnees.set(res); this.moyenChoisi = res.moyen_paiement ?? 'wave'; this.numero = res.numero_paiement ?? ''; },
      error: err => this.erreur.set(err.error?.message ?? 'Impossible de charger votre argent.'),
    });
  }

  enregistrer(): void {
    this.api.updateProfile({ moyen_paiement: this.moyenChoisi, numero_paiement: this.numero.trim() || null }).subscribe({
      next: res => { this.message.set('Numéro de réception enregistré.'); this.erreur.set(null); if (res?.utilisateur) this.auth.mettreAJourUtilisateur(res.utilisateur); this.charger(); },
      error: err => this.erreur.set(err.error?.errors?.numero_paiement?.[0] ?? err.error?.message ?? 'Numéro non enregistré.'),
    });
  }

  moyen(m: string | null): string { return MOYENS[m ?? 'wave'] ?? 'compte'; }
  etat(s: string): string { return ETATS_VERSEMENT[s] ?? s; }
  beneficiaire(b: string): string { return BENEFICIAIRES[b] ?? b; }
}
