import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-utilisateurs',
  standalone: true,
  imports: [ReactiveFormsModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Gestion des utilisateurs</h1>
        <div class="btn-group">
          <span class="subtitle">{{ utilisateurs().length }} utilisateur(s)</span>
          <button class="btn-primary" (click)="showForm.set(!showForm())">
            {{ showForm() ? 'Annuler' : '+ Ajouter un utilisateur' }}
          </button>
        </div>
      </div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <!-- Formulaire d'ajout -->
      @if (showForm()) {
        <div class="card form-card">
          <h2>Nouvel utilisateur</h2>
          <form [formGroup]="form" (ngSubmit)="creerUtilisateur()">
            <div class="form-row">
              <div class="form-group">
                <label>Prénom *</label>
                <input type="text" formControlName="prenom" placeholder="Prénom" />
              </div>
              <div class="form-group">
                <label>Nom *</label>
                <input type="text" formControlName="nom" placeholder="Nom" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Email *</label>
                <input type="email" formControlName="email" placeholder="email@example.sn" />
              </div>
              <div class="form-group">
                <label>Téléphone</label>
                <input type="tel" formControlName="telephone" placeholder="+221 77 000 00 00" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Rôle *</label>
                <select formControlName="role">
                  <option value="">Sélectionner un rôle</option>
                  <option value="producteur">Producteur</option>
                  <option value="gestionnaire_entrepot">Gestionnaire d'entrepôt</option>
                  <option value="acheteur_gros">Acheteur en gros</option>
                  <option value="transporteur">Transporteur</option>
                  <option value="administrateur">Administrateur</option>
                </select>
              </div>
              <div class="form-group">
                <label>Mot de passe *</label>
                <input type="password" formControlName="mot_de_passe" placeholder="Minimum 8 caractères" />
              </div>
            </div>
            <div class="btn-group">
              <button type="submit" class="btn-primary" [disabled]="loadingForm()">
                {{ loadingForm() ? 'Création...' : 'Créer le compte' }}
              </button>
              <button type="button" class="btn-ghost" (click)="showForm.set(false)">Annuler</button>
            </div>
          </form>
        </div>
      }

      <!-- Filtres -->
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

        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (utilisateurs().length === 0) {
          <p class="empty">Aucun utilisateur.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Nom</th><th>Email</th><th>Téléphone</th><th>Rôle</th><th>Statut</th><th>Actions</th></tr>
            </thead>
            <tbody>
              @for (u of utilisateurs(); track u.id) {
                <tr>
                  <td><strong>{{ u.prenom }} {{ u.nom }}</strong></td>
                  <td>{{ u.email }}</td>
                  <td>{{ u.telephone ?? '—' }}</td>
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
  showForm     = signal(false);
  loadingForm  = signal(false);
  message      = signal<string | null>(null);
  erreur       = signal<string | null>(null);
  form!: FormGroup;
  private params: any = {};

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      prenom:       ['', Validators.required],
      nom:          ['', Validators.required],
      email:        ['', [Validators.required, Validators.email]],
      telephone:    [''],
      role:         ['', Validators.required],
      mot_de_passe: ['', [Validators.required, Validators.minLength(8)]],
    });
    this.charger();
  }

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

  creerUtilisateur(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loadingForm.set(true);
    this.erreur.set(null);
    this.api.createUtilisateur(this.form.value).subscribe({
      next: res => {
        this.utilisateurs.update(list => [res.utilisateur, ...list]);
        this.message.set(`Compte créé pour ${this.form.value.prenom} ${this.form.value.nom}.`);
        this.form.reset();
        this.showForm.set(false);
        this.loadingForm.set(false);
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de la création.');
        this.loadingForm.set(false);
      },
    });
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
