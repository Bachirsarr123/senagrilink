import { Component, inject, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-header">
          <h1>🌾 SenAgriLink</h1>
          <p>Plateforme de gestion agricole</p>
        </div>
        <h2>Connexion</h2>

        @if (infoSession && !erreur()) {
          <div class="alert alert-info">{{ infoSession }}</div>
        }
        @if (erreur()) {
          <div class="alert alert-error" role="alert">{{ erreur() }}</div>
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

          <button type="submit" class="btn-primary" [disabled]="loading()">
            {{ loading() ? 'Connexion...' : 'Se connecter' }}
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
  // Signaux : sans zone.js, l'affichage se met à jour dès la réponse de l'API
  // (un simple champ n'était redessiné qu'à l'interaction suivante).
  loading = signal(false);
  erreur  = signal<string | null>(null);

  /** Message affiché après une fin de session imposée par l'API (voir authInterceptor). */
  readonly infoSession: string | null = {
    session_expiree: 'Votre session a expiré. Veuillez vous reconnecter.',
    compte_bloque:   'Votre compte a été bloqué. Contactez l\'administrateur.',
  }[inject(ActivatedRoute).snapshot.queryParamMap.get('raison') ?? ''] ?? null;

  constructor(private fb: FormBuilder, private auth: AuthService) {
    this.form = this.fb.group({
      email:        ['', [Validators.required, Validators.email]],
      mot_de_passe: ['', Validators.required],
    });
  }

  onSubmit(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading.set(true);
    this.erreur.set(null);

    this.auth.login(this.form.value).subscribe({
      next: () => this.auth.redirectAfterLogin(),
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur de connexion.');
        this.loading.set(false);
      },
    });
  }
}
