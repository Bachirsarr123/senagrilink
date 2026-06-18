import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-header">
          <h1>🌾 AgriPlatform</h1>
          <p>Plateforme de gestion agricole</p>
        </div>
        <h2>Connexion</h2>

        @if (erreur) {
          <div class="alert alert-error">{{ erreur }}</div>
        }

        <form [formGroup]="form" (ngSubmit)="onSubmit()">
          <div class="form-group">
            <label for="email">Adresse email</label>
            <input id="email" type="email" formControlName="email" placeholder="votre@email.sn" />
            @if (form.get('email')?.invalid && form.get('email')?.touched) {
              <span class="field-error">Email invalide</span>
            }
          </div>

          <div class="form-group">
            <label for="mdp">Mot de passe</label>
            <input id="mdp" type="password" formControlName="mot_de_passe" placeholder="••••••••" />
            @if (form.get('mot_de_passe')?.invalid && form.get('mot_de_passe')?.touched) {
              <span class="field-error">Mot de passe requis</span>
            }
          </div>

          <button type="submit" class="btn-primary" [disabled]="loading">
            {{ loading ? 'Connexion...' : 'Se connecter' }}
          </button>
        </form>

        <p class="auth-link">Pas encore de compte ? <a routerLink="/register">S'inscrire</a></p>
      </div>
    </div>
  `,
  styleUrls: ['../auth.styles.scss'],
})
export class LoginComponent {
  form: FormGroup;
  loading = false;
  erreur: string | null = null;

  constructor(private fb: FormBuilder, private auth: AuthService) {
    this.form = this.fb.group({
      email:        ['', [Validators.required, Validators.email]],
      mot_de_passe: ['', Validators.required],
    });
  }

  onSubmit(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading = true;
    this.erreur  = null;

    this.auth.login(this.form.value).subscribe({
      next: () => this.auth.redirectAfterLogin(),
      error: err => {
        this.erreur  = err.error?.message ?? 'Erreur de connexion.';
        this.loading = false;
      },
    });
  }
}
