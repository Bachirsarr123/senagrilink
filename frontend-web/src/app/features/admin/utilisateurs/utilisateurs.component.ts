import { Component, OnInit, signal } from '@angular/core';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-utilisateurs',
  standalone: true,
  imports: [],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Gestion des utilisateurs</h1>
        <span class="subtitle">{{ utilisateurs().length }} utilisateur(s)</span>
      </div>

      <div class="card">
        <div class="filters">
          <select (change)="filtrerRole($event)">
            <option value="">Tous les rôles</option>
            <option value="producteur">Producteur</option>
            <option value="gestionnaire_entrepot">Gestionnaire</option>
            <option value="acheteur_gros">Acheteur</option>
            <option value="transporteur">Transporteur</option>
            <option value="administrateur">Administrateur</option>
          </select>
          <select (change)="filtrerStatut($event)">
            <option value="">Tous les statuts</option>
            <option value="actif">Actif</option>
            <option value="bloque">Bloqué</option>
          </select>
        </div>

        @if (chargement()) { <p class="loading">Chargement...</p> }
        @else if (utilisateurs().length === 0) { <p class="empty">Aucun utilisateur.</p> }
        @else {
          <table class="table">
            <thead>
              <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (u of utilisateurs(); track u.id) {
                <tr>
                  <td><strong>{{ u.prenom }} {{ u.nom }}</strong></td>
                  <td>{{ u.email }}</td>
                  <td><span class="badge badge-role">{{ u.role }}</span></td>
                  <td><span class="badge badge-{{ u.statut }}">{{ u.statut }}</span></td>
                  <td>
                    @if (u.statut === 'actif') {
                      <button class="btn-danger btn-sm" (click)="bloquer(u)">Bloquer</button>
                    } @else {
                      <button class="btn-success btn-sm" (click)="debloquer(u)">Débloquer</button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class UtilisateursComponent implements OnInit {
  utilisateurs = signal<any[]>([]);
  chargement   = signal(true);
  private params: any = {};

  constructor(private api: ApiService) {}

  ngOnInit(): void { this.charger(); }

  charger(): void {
    this.chargement.set(true);
    this.api.getUtilisateurs(this.params).subscribe({
      next: res => { this.utilisateurs.set(res.utilisateurs); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  filtrerRole(e: Event): void {
    const v = (e.target as HTMLSelectElement).value;
    if (v) this.params.role = v; else delete this.params.role;
    this.charger();
  }

  filtrerStatut(e: Event): void {
    const v = (e.target as HTMLSelectElement).value;
    if (v) this.params.statut = v; else delete this.params.statut;
    this.charger();
  }

  bloquer(u: any): void {
    if (!confirm(`Bloquer le compte de ${u.prenom} ${u.nom} ?`)) return;
    this.api.bloquerUtilisateur(u.id).subscribe({
      next: () => this.utilisateurs.update(list => list.map(x => x.id === u.id ? { ...x, statut: 'bloque' } : x)),
    });
  }

  debloquer(u: any): void {
    this.api.debloquerUtilisateur(u.id).subscribe({
      next: () => this.utilisateurs.update(list => list.map(x => x.id === u.id ? { ...x, statut: 'actif' } : x)),
    });
  }
}
