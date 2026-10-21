import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';

/** Tableau des exceptions de l'administrateur (lot 5) : ce qui est bloqué, en retard ou anormal. */
@Component({
  selector: 'app-exceptions',
  standalone: true,
  imports: [DatePipe, RouterLink],
  template: `
    <div class="card">
      <h2>Exceptions à surveiller
        @if (parGravite(); as g) { <small>· {{ g.haute }} grave(s), {{ g.moyenne }} à suivre, {{ g.basse }} mineure(s)</small> }</h2>
      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }
      @else if (exceptions().length === 0 && charge()) { <p class="empty">✅ Aucune exception : tout se déroule normalement.</p> }
      <ul class="exceptions">
        @for (e of exceptions(); track $index) {
          <li class="exception exception--{{ e.gravite }}">
            <span class="badge" [class.badge-probleme]="e.gravite === 'haute'" [class.badge-en_attente]="e.gravite === 'moyenne'" [class.badge-epuise]="e.gravite === 'basse'">{{ libelleGravite(e.gravite) }}</span>
            <span class="exception__texte"><code class="code-lot">{{ e.numero }}</code> {{ e.message }}
              @if (e.depuis) { <small>· depuis le {{ e.depuis | date:'dd/MM à HH:mm' }}</small> }</span>
            @if (e.lien?.sujet_type === 'commande') { <a class="btn-ghost btn-sm" [routerLink]="['/commandes', e.lien.sujet_id]">Voir</a> }
          </li>
        }
      </ul>
    </div>
  `,
  styles: [`
    .exceptions { list-style:none; margin:0; padding:0; display:grid; gap:.4rem; }
    .exception { display:flex; gap:.6rem; align-items:flex-start; flex-wrap:wrap; padding:.5rem .6rem; border-radius:8px; background:#f9fafb; }
    .exception--haute { background:#fef2f2; }
    .exception__texte { flex:1; min-width:200px; overflow-wrap:anywhere; }
    h2 small { font-weight:400; color:#6b7280; font-size:.85rem; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class ExceptionsComponent implements OnInit {
  private api = inject(ApiService);
  exceptions = signal<any[]>([]);
  parGravite = signal<any | null>(null);
  erreur = signal<string | null>(null);
  charge = signal(false);

  ngOnInit(): void {
    this.api.getExceptions().subscribe({
      next: r => { this.exceptions.set(r.exceptions ?? []); this.parGravite.set(r.par_gravite ?? null); this.charge.set(true); },
      error: err => this.erreur.set(err.error?.message ?? 'Impossible de charger les exceptions.'),
    });
  }

  libelleGravite(g: string): string { return ({ haute: 'Grave', moyenne: 'À suivre', basse: 'Mineur' } as Record<string, string>)[g] ?? g; }
}
