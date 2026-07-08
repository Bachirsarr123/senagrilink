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
          <h1>🌾 SenAgriLink</h1>
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
              <option value="transporteur">Transporteur</option>
            </select>
          </div>

          <!-- Champs conditionnels Producteur -->
          @if (selectedRole === 'producteur') {
            <div class="form-row">
              <div class="form-group">
                <label>Région *</label>
                <input type="text" formControlName="region" placeholder="Thiès" />
              </div>
              <div class="form-group">
                <label>Types de cultures *</label>
                <input type="text" formControlName="types_cultures" placeholder="Mil, Arachide" />
              </div>
            </div>
            <div class="form-group">
              <label>Superficie exploitée (hectares) *</label>
              <input type="number" formControlName="superficie" min="0.01" step="0.01" placeholder="ex : 5" />
            </div>
          }

          <!-- Champs conditionnels Gestionnaire -->
          @if (selectedRole === 'gestionnaire_entrepot') {
            <div class="form-row">
              <div class="form-group">
                <label>Nom de l'entrepôt *</label>
                <input type="text" formControlName="nom_entrepot" placeholder="Entrepôt Central" />
              </div>
              <div class="form-group">
                <label>Localisation *</label>
                <input type="text" formControlName="localisation" placeholder="Dakar, Sénégal" />
              </div>
            </div>
            <div class="form-group">
              <label>Capacité de stockage (kg) *</label>
              <input type="number" formControlName="capacite" min="0.01" step="0.01" placeholder="ex : 5000" />
            </div>
          }

          <!-- Champs conditionnels Acheteur -->
          @if (selectedRole === 'acheteur_gros') {
            <div class="form-row">
              <div class="form-group">
                <label>Type d'activité *</label>
                <input type="text" formControlName="type_activite" placeholder="Commerce, Restauration..." />
              </div>
              <div class="form-group">
                <label>Volume d'achat mensuel (kg) *</label>
                <input type="number" formControlName="volume_achat_mensuel" min="0.01" step="0.01" placeholder="ex : 2000" />
              </div>
            </div>
          }

          <!-- Champs conditionnels Transporteur -->
          @if (selectedRole === 'transporteur') {
            <div class="form-row">
              <div class="form-group">
                <label>Type de véhicule *</label>
                <input type="text" formControlName="type_vehicule" placeholder="Camion, Camionnette..." />
              </div>
              <div class="form-group">
                <label>Capacité de charge (kg) *</label>
                <input type="number" formControlName="capacite_charge" min="0.01" step="0.01" placeholder="ex : 1500" />
              </div>
            </div>
            <div class="form-group">
              <label>Zone de couverture *</label>
              <input type="text" formControlName="zone" placeholder="Dakar et environs" />
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

  // Champs métier propres à chaque rôle, requis uniquement quand ce rôle est sélectionné.
  private readonly CHAMPS_PAR_ROLE: Record<string, string[]> = {
    producteur:            ['region', 'types_cultures', 'superficie'],
    gestionnaire_entrepot: ['nom_entrepot', 'localisation', 'capacite'],
    acheteur_gros:         ['type_activite', 'volume_achat_mensuel'],
    transporteur:          ['type_vehicule', 'capacite_charge', 'zone'],
  };

  constructor(private fb: FormBuilder, private auth: AuthService) {
    this.form = this.fb.group({
      nom:                       ['', Validators.required],
      prenom:                    ['', Validators.required],
      email:                     ['', [Validators.required, Validators.email]],
      role:                      ['', Validators.required],
      mot_de_passe:              ['', [Validators.required, Validators.minLength(8)]],
      mot_de_passe_confirmation: ['', Validators.required],
      region:                [''], types_cultures:  [''], superficie: [null],
      nom_entrepot:          [''], localisation:    [''], capacite:   [null],
      type_activite:         [''], volume_achat_mensuel: [null],
      type_vehicule:         [''], capacite_charge: [null], zone: [''],
    });
  }

  onRoleChange(): void {
    this.selectedRole = this.form.get('role')?.value;

    // Seuls les champs du rôle choisi sont obligatoires.
    for (const champs of Object.values(this.CHAMPS_PAR_ROLE)) {
      for (const champ of champs) {
        this.form.get(champ)?.clearValidators();
      }
    }
    for (const champ of this.CHAMPS_PAR_ROLE[this.selectedRole] ?? []) {
      this.form.get(champ)?.setValidators(Validators.required);
    }
    for (const champs of Object.values(this.CHAMPS_PAR_ROLE)) {
      for (const champ of champs) {
        this.form.get(champ)?.updateValueAndValidity({ emitEvent: false });
      }
    }
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
