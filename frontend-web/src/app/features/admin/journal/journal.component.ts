import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';

const SUJETS: { code: string; libelle: string }[] = [
  { code: '', libelle: 'Tout' }, { code: 'utilisateur', libelle: 'Comptes et connexions' }, { code: 'commande', libelle: 'Commandes' },
  { code: 'livraison', libelle: 'Livraisons' }, { code: 'reservation', libelle: 'Dépôts' }, { code: 'production', libelle: 'Récoltes' },
];

/** Libellés lisibles des actions les plus courantes du journal. */
const ACTIONS: Record<string, string> = {
  inscription: 'Inscription', connexion: 'Connexion', echec_connexion: 'Connexion échouée (mot de passe)', connexion_refusee: 'Connexion refusée (compte non actif)',
  deconnexion: 'Déconnexion', modification_profil: 'Profil modifié', validation_compte: 'Compte validé', refus_compte: 'Compte refusé',
  blocage: 'Compte bloqué', deblocage: 'Compte débloqué', creation_par_admin: 'Compte créé par l\'administrateur',
  creation: 'Création', confirmation: 'Confirmation', annulation: 'Annulation', reception: 'Réception', livraison: 'Remise',
};

/** Journal d'audit (lot 6) : qui a fait quoi, quand, depuis quelle adresse. */
@Component({
  selector: 'app-journal',
  standalone: true,
  imports: [DatePipe, FormsModule, LibelleStatutPipe],
  template: `
    <div class="page">
      <div class="page-header"><h1>Journal d'audit</h1><span class="subtitle">{{ total() }} entrée(s)</span></div>

      <div class="card filtres">
        <label>Type <select [(ngModel)]="sujet" (ngModelChange)="charger(1)">@for (s of sujets; track s.code) { <option [value]="s.code">{{ s.libelle }}</option> }</select></label>
        <label>Action <input [(ngModel)]="action" (keyup.enter)="charger(1)" placeholder="ex. connexion" /></label>
        <label>Du <input type="date" [(ngModel)]="du" (change)="charger(1)" /></label>
        <label>Au <input type="date" [(ngModel)]="au" (change)="charger(1)" /></label>
        <button class="btn-secondary btn-sm" (click)="charger(1)">Filtrer</button>
      </div>

      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }

      <div class="card">
        @if (entrees().length === 0) { <p class="empty">Aucune entrée pour ces filtres.</p> }
        <div class="table-scroll"><table class="table">
          <thead><tr><th>Date</th><th>Auteur</th><th>Action</th><th>Sur</th><th>Détail</th><th>Adresse</th></tr></thead>
          <tbody>
            @for (e of entrees(); track e.id) {
              <tr>
                <td>{{ e.date | date:'dd/MM/yyyy HH:mm:ss' }}</td>
                <td>@if (e.auteur) { {{ e.auteur.nom }}<br><small class="text-muted">{{ e.auteur.role | libelleStatut }}</small> } @else { <span class="text-muted">—</span> }</td>
                <td>{{ libelle(e.action) }}</td>
                <td>{{ e.sujet_type }} #{{ e.sujet_id }}</td>
                <td><small>@if (e.ancien_statut || e.nouveau_statut) { {{ e.ancien_statut | libelleStatut }} → {{ e.nouveau_statut | libelleStatut }} }
                  @if (e.motif) { · {{ e.motif }} }@if (e.details?.champs) { · {{ e.details.champs.join(', ') }} }</small></td>
                <td><small class="text-muted">{{ e.adresse_ip ?? '—' }}</small></td>
              </tr>
            }
          </tbody>
        </table></div>
        @if (pages() > 1) {
          <div class="btn-group pagination">
            <button class="btn-ghost btn-sm" [disabled]="page() <= 1" (click)="charger(page() - 1)">← Précédentes</button>
            <span>Page {{ page() }} / {{ pages() }}</span>
            <button class="btn-ghost btn-sm" [disabled]="page() >= pages()" (click)="charger(page() + 1)">Suivantes →</button>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    .filtres { display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end; }
    .filtres label { display:grid; gap:.25rem; font-size:.8rem; color:#6b7280; }
    .filtres select, .filtres input { padding:.45rem .6rem; border:1px solid #e5e7eb; border-radius:6px; }
    .pagination { margin-top:.75rem; align-items:center; }
    .text-muted { color:#6b7280; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class JournalComponent implements OnInit {
  private api = inject(ApiService);
  readonly sujets = SUJETS;
  sujet = '';
  action = '';
  du = '';
  au = '';
  entrees = signal<any[]>([]);
  page = signal(1);
  pages = signal(1);
  total = signal(0);
  erreur = signal<string | null>(null);

  ngOnInit(): void { this.charger(1); }

  libelle(a: string): string { return ACTIONS[a] ?? a.replace(/_/g, ' '); }

  charger(page: number): void {
    const params: Record<string, string | number> = { page };
    if (this.sujet) params['sujet_type'] = this.sujet;
    if (this.action.trim()) params['action'] = this.action.trim();
    if (this.du) params['du'] = this.du;
    if (this.au) params['au'] = this.au;
    this.api.getJournal(params).subscribe({
      next: r => { this.entrees.set(r.entrees ?? []); this.page.set(r.page); this.pages.set(r.pages); this.total.set(r.total); this.erreur.set(null); },
      error: err => this.erreur.set(err.error?.message ?? 'Impossible de charger le journal.'),
    });
  }
}
