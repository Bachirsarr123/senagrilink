import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { ETATS_PAIEMENT, MOYENS } from '../../../shared/paiement/paiement';

/** Administrateur (lot 12) : paiements récents et litiges à trancher (verser ou rembourser). */
@Component({
  selector: 'app-admin-paiements',
  standalone: true,
  imports: [DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Paiements</h1></div>
      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      <div class="card">
        <h2>⚖️ Litiges à trancher</h2>
        @for (c of donnees()?.litiges ?? []; track c.id) {
          <div class="ligne"><span><strong>{{ c.numero_commande }}</strong> — {{ c.produit }} · {{ c.total_a_payer | number:'1.0-0' }} FCFA</span>
            <span class="btn-group">
              <button class="btn-primary btn-sm" (click)="decider(c, 'liberer')">Verser au producteur</button>
              <button class="btn-danger btn-sm" (click)="decider(c, 'rembourser')">Rembourser l'acheteur</button>
            </span></div>
        } @empty { <p class="vide">Aucun litige.</p> }
      </div>
      <div class="card">
        <h2>Paiements récents</h2>
        @for (p of donnees()?.paiements ?? []; track p.id) {
          <div class="ligne"><span><code>{{ p.reference }}</code> · {{ p.acheteur?.utilisateur?.prenom }} {{ p.acheteur?.utilisateur?.nom }} · {{ moyen(p.moyen) }}</span>
            <span><strong>{{ p.montant | number:'1.0-0' }} FCFA</strong> · {{ p.statut }} · {{ p.created_at | date:'dd/MM HH:mm' }}</span></div>
        } @empty { <p class="vide">Aucun paiement.</p> }
      </div>
    </div>
  `,
  styles: [`.ligne { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; padding:.5rem 0; border-top:1px solid #f3f4f6; } .vide { color:#6b7280; }`],
  styleUrls: ['../../shared.styles.scss'],
})
export class AdminPaiementsComponent implements OnInit {
  private readonly api = inject(ApiService);
  donnees = signal<any>(null);
  message = signal<string | null>(null);
  ngOnInit(): void { this.charger(); }
  charger(): void { this.api.getPaiementsAdmin().subscribe({ next: res => this.donnees.set(res) }); }
  moyen(m: string): string { return MOYENS[m] ?? m; }
  etat(s: string): string { return ETATS_PAIEMENT[s] ?? s; }
  decider(c: any, decision: 'liberer' | 'rembourser'): void {
    if (!confirm(decision === 'liberer' ? `Verser l'argent de ${c.numero_commande} à chacun ?` : `Rembourser l'acheteur de ${c.numero_commande} ?`)) return;
    this.api.deciderPaiement(c.id, decision).subscribe({ next: res => { this.message.set(res.message); this.charger(); } });
  }
}
