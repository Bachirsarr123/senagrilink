import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { MOYENS } from '../../../shared/paiement/paiement';

/**
 * Page de paiement (lot 12). En attendant Wave Business, elle simule l'écran du
 * service de paiement : l'acheteur confirme, l'argent est mis de côté jusqu'à
 * la réception. Avec le vrai service, l'acheteur sera envoyé sur sa page.
 */
@Component({
  selector: 'app-paiement',
  standalone: true,
  imports: [DecimalPipe, RouterLink],
  template: `
    <div class="page paiement">
      @if (paiement(); as p) {
        <div class="card ecran" [class.ecran--orange]="p.moyen === 'orange_money'">
          <div class="marque">{{ moyen(p.moyen) }}</div>
          @if (p.fournisseur === 'simulation') { <p class="simulation">Simulation : aucun argent réel n'est débité.</p> }
          <p class="montant">{{ p.montant | number:'1.0-0' }} FCFA</p>
          <p>Payer SenAgriLink · {{ p.commandes.length }} commande(s)</p>
          <ul>
            @for (c of p.commandes; track c.id) { <li>{{ c.numero_commande }} — {{ c.produit }} : {{ c.total_a_payer | number:'1.0-0' }} FCFA</li> }
          </ul>
          @if (p.statut === 'initie') {
            <p class="aide">Depuis le numéro {{ p.telephone }}</p>
            <button class="btn-primary gros" [disabled]="envoi()" (click)="confirmer('reussi')">{{ envoi() ? 'Paiement…' : 'Confirmer le paiement' }}</button>
            <button class="btn-ghost" [disabled]="envoi()" (click)="confirmer('echoue')">Annuler</button>
          } @else if (p.statut === 'reussi') {
            <p class="succes">✅ {{ message() ?? 'Paiement réussi.' }}</p>
            <p class="aide">L'argent est mis de côté : il ne sera versé au producteur qu'une fois la marchandise reçue.</p>
            <a class="btn-primary gros" routerLink="/acheteur/mes-commandes">Voir mes commandes</a>
          } @else {
            <p class="echec">{{ message() ?? 'Paiement non abouti.' }}</p>
            <a class="btn-primary gros" routerLink="/acheteur/mes-commandes">Réessayer depuis mes commandes</a>
          }
        </div>
      } @else if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
      @else { <p class="loading">Chargement…</p> }
    </div>
  `,
  styles: [`
    .paiement { max-width:440px; margin:0 auto; }
    .ecran { text-align:center; display:grid; gap:.6rem; border-top:6px solid #1dc8f2; }
    .ecran--orange { border-top-color:#ff7900; }
    .marque { font-size:1.5rem; font-weight:800; color:#0b5ea8; }
    .ecran--orange .marque { color:#ff7900; }
    .simulation { background:#fef3c7; color:#92400e; border-radius:6px; padding:.4rem; margin:0; font-size:.9rem; }
    .montant { font-size:2rem; font-weight:800; margin:.2rem 0; }
    ul { list-style:none; padding:0; margin:0; color:#4b5563; font-size:.9rem; }
    .aide { color:#6b7280; margin:0; }
    .gros { min-height:52px; font-size:1.1rem; font-weight:700; }
    .succes { color:#166534; font-weight:700; }
    .echec { color:#b91c1c; font-weight:700; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class PaiementComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  paiement = signal<any>(null);
  erreur = signal<string | null>(null);
  message = signal<string | null>(null);
  envoi = signal(false);

  ngOnInit(): void {
    this.api.getPaiement(this.route.snapshot.paramMap.get('reference') ?? '').subscribe({
      next: res => this.paiement.set(res.paiement),
      error: err => this.erreur.set(err.error?.message ?? 'Paiement introuvable.'),
    });
  }

  moyen(m: string): string { return MOYENS[m] ?? m; }

  confirmer(resultat: 'reussi' | 'echoue'): void {
    this.envoi.set(true);
    this.api.simulerPaiement(this.paiement().reference, resultat).subscribe({
      next: res => { this.envoi.set(false); this.message.set(res.message); this.paiement.set(res.paiement); },
      error: err => { this.envoi.set(false); this.erreur.set(err.error?.message ?? 'Paiement impossible.'); },
    });
  }
}
