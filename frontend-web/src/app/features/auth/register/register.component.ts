import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';
import { Role } from '../../../core/auth/auth.model';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <div class="auth-page">
      <div class="auth-card auth-card--wide">
        <div class="auth-header">
          <h1>🌾 AgriPlatform</h1>
        </div>
        <h2>Créer un compte</h2>

        @if (erreur) {
          <div class="alert alert-error">{{ erreur }}</div>
        }

        <form [formGroup]="form" (ngSubmit)="onSubmit()">
          <div class="form-row">
            <div class="form-group">
              <label>Nom</label>
              <input type="text" formControlName="nom" placeholder="Diallo" />
            </div>
            <div class="form-group">
              <label>Prénom</label>
              <input type="text" formControlName="prenom" placeholder="Mamadou" />
            </div>
          </div>

          <div class="form-group">
            <label>Email</label>
            <input type="email" formControlName="email" placeholder="votre@email.sn" />
          </div>

          <div class="form-group">
            <label>Rôle</label>
            <select formControlName="role" (change)="onRoleChange()">
              <option value="">-- Choisir un rôle --</option>
              <option value="producteur">Producteur</option>
              <option value="gestionnaire_entrepot">Gestionnaire d'entrepôt</option>
              <option value="acheteur_gros">Acheteur en gros</option>
            </select>
          </div>

          <!-- Champs conditionnels Producteur -->
          @if (selectedRole === 'producteur') {
            <div class="form-row">
              <div class="form-group">
                <label>Région</label>
                <input type="text" formControlName="region" placeholder="Thiès" />
              </div>
              <div class="form-group">
                <label>Types de cultures</label>
                <input type="text" formControlName="types_cultures" placeholder="Mil, Arachide" />
              </div>
            </div>
          }

          <!-- Champs conditionnels Gestionnaire -->
          @if (selectedRole === 'gestionnaire_entrepot') {
            <div class="form-row">
              <div class="form-group">
                <label>Nom de l'entrepôt</label>
                <input type="text" formControlName="nom_entrepot" placeholder="Entrepôt Central" />
              </div>
              <div class="form-group">
                <label>Localisation</label>
                <input type="text" formControlName="localisation" placeholder="Dakar, Sénégal" />
              </div>
            </div>
          }

          <!-- Champs conditionnels Acheteur -->
          @if (selectedRole === 'acheteur_gros') {
            <div class="form-group">
              <label>Type d'activité</label>
              <input type="text" formControlName="type_activite" placeholder="Commerce, Restauration..." />
            </div>
          }

          <div class="form-row">
            <div class="form-group">
              <label>Mot de passe</label>
              <input type="password" formControlName="mot_de_passe" placeholder="••••••••" />
            </div>
            <div class="form-group">
              <label>Confirmer le mot de passe</label>
              <input type="password" formControlName="mot_de_passe_confirmation" placeholder="••••••••" />
            </div>
          </div>

          <button type="submit" class="btn-primary" [disabled]="loading">
            {{ loading ? 'Inscription...' : "S'inscrire" }}
          </button>
        </form>

        <p class="auth-link">Déjà inscrit ? <a routerLink="/login">Se connecter</a></p>
      </div>
    </div>
  `,
  styleUrls: ['../auth.styles.scss'],
})
export class RegisterComponent {
  form: FormGroup;
  loading       = false;
  erreur: string | null = null;
  selectedRole: Role | '' = '';

  constructor(private fb: FormBuilder, private auth: AuthService) {
    this.form = this.fb.group({
      nom:                       ['', Validators.required],
      prenom:                    ['', Validators.required],
      email:                     ['', [Validators.required, Validators.email]],
      role:                      ['', Validators.required],
      mot_de_passe:              ['', [Validators.required, Validators.minLength(8)]],
      mot_de_passe_confirmation: ['', Validators.required],
      region:         [''], types_cultures:  [''],
      nom_entrepot:   [''], localisation:    [''],
      type_activite:  [''],
    });
  }

  onRoleChange(): void {
    this.selectedRole = this.form.get('role')?.value;
  }

  onSubmit(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading = true;
    this.erreur  = null;

    this.auth.register(this.form.value).subscribe({
      next: () => this.auth.redirectAfterLogin(),
      error: err => {
        this.erreur  = err.error?.message ?? 'Erreur lors de l\'inscription.';
        this.loading = false;
      },
    });
  }
}
