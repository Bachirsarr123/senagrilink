import { Component, OnInit, signal, inject } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-profil',
  standalone: true,
  imports: [ReactiveFormsModule],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Mon profil</h1>
        <span class="subtitle">Informations personnelles et paramètres du compte</span>
      </div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <!-- Informations du compte -->
      <div class="card" style="margin-bottom:1.5rem">
        <h2>Informations du compte</h2>
        <div class="stat-row">
          <span style="color:#6b7280">Rôle</span>
          <span class="badge badge-role">{{ roleFr() }}</span>
        </div>
        <div class="stat-row">
          <span style="color:#6b7280">Statut</span>
          <span class="badge badge-{{ utilisateur()?.statut }}">{{ utilisateur()?.statut }}</span>
        </div>
      </div>

      <!-- Formulaire de modification -->
      <div class="card form-card">
        <h2>Modifier mes informations</h2>
        <form [formGroup]="form" (ngSubmit)="sauvegarder()">
          <div class="form-row">
            <div class="form-group">
              <label>Prénom *</label>
              <input type="text" formControlName="prenom" placeholder="Votre prénom" />
              @if (form.get('prenom')?.invalid && form.get('prenom')?.touched) {
                <span class="field-error" style="color:#dc2626;font-size:.8rem">Prénom requis</span>
              }
            </div>
            <div class="form-group">
              <label>Nom *</label>
              <input type="text" formControlName="nom" placeholder="Votre nom" />
              @if (form.get('nom')?.invalid && form.get('nom')?.touched) {
                <span class="field-error" style="color:#dc2626;font-size:.8rem">Nom requis</span>
              }
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Email *</label>
              <input type="email" formControlName="email" placeholder="votre@email.sn" />
              @if (form.get('email')?.invalid && form.get('email')?.touched) {
                <span class="field-error" style="color:#dc2626;font-size:.8rem">Email invalide</span>
              }
            </div>
            <div class="form-group">
              <label>Téléphone</label>
              <input type="tel" formControlName="telephone" placeholder="+221 77 000 00 00" />
            </div>
          </div>

          <div class="form-group">
            <label>Adresse</label>
            <input type="text" formControlName="adresse" placeholder="Votre adresse" />
          </div>

          <!-- Profil Producteur -->
          @if (role() === 'producteur') {
            <div style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:.5rem">
              <h3 style="font-size:1rem;margin-bottom:.75rem;color:#374151">Profil producteur</h3>
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
              <div class="form-group">
                <label>Superficie exploitée (hectares)</label>
                <input type="number" formControlName="superficie" min="0" step="0.01" />
              </div>
            </div>
          }

          <!-- Profil Gestionnaire -->
          @if (role() === 'gestionnaire_entrepot') {
            <div style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:.5rem">
              <h3 style="font-size:1rem;margin-bottom:.75rem;color:#374151">Profil entrepôt</h3>
              <div class="form-row">
                <div class="form-group">
                  <label>Nom de l'entrepôt</label>
                  <input type="text" formControlName="nom_entrepot" placeholder="Entrepôt Central" />
                </div>
                <div class="form-group">
                  <label>Capacité de stockage (kg)</label>
                  <input type="number" formControlName="capacite" min="0" step="0.01" />
                </div>
              </div>
              <div class="form-group">
                <label>Localisation</label>
                <input type="text" formControlName="localisation" placeholder="Dakar, Sénégal" />
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Latitude (pour la carte de suivi)</label>
                  <input type="number" formControlName="latitude" step="0.0000001" placeholder="ex : 14.7167" />
                </div>
                <div class="form-group">
                  <label>Longitude (pour la carte de suivi)</label>
                  <input type="number" formControlName="longitude" step="0.0000001" placeholder="ex : -17.4677" />
                </div>
              </div>
            </div>
          }

          <!-- Profil Acheteur -->
          @if (role() === 'acheteur_gros') {
            <div style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:.5rem">
              <h3 style="font-size:1rem;margin-bottom:.75rem;color:#374151">Profil acheteur</h3>
              <div class="form-row">
                <div class="form-group">
                  <label>Type d'activité</label>
                  <input type="text" formControlName="type_activite" placeholder="Commerce, Restauration..." />
                </div>
                <div class="form-group">
                  <label>Volume d'achat mensuel (kg)</label>
                  <input type="number" formControlName="volume_achat_mensuel" min="0" step="0.01" />
                </div>
              </div>
            </div>
          }

          <!-- Profil Transporteur -->
          @if (role() === 'transporteur') {
            <div style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:.5rem">
              <h3 style="font-size:1rem;margin-bottom:.75rem;color:#374151">Profil transporteur</h3>
              <div class="form-row">
                <div class="form-group">
                  <label>Type de véhicule</label>
                  <input type="text" formControlName="type_vehicule" placeholder="Camion, Camionnette..." />
                </div>
                <div class="form-group">
                  <label>Capacité de charge (kg)</label>
                  <input type="number" formControlName="capacite_charge" min="0" step="0.01" />
                </div>
              </div>
              <div class="form-group">
                <label>Zone de couverture</label>
                <input type="text" formControlName="zone" placeholder="Dakar et environs" />
              </div>
            </div>
          }

          <div style="border-top:1px solid #e5e7eb;padding-top:1rem;margin-top:.5rem">
            <h3 style="font-size:1rem;margin-bottom:.75rem;color:#374151">
              Changer de mot de passe (laisser vide pour ne pas modifier)
            </h3>
            <div class="form-row">
              <div class="form-group">
                <label>Nouveau mot de passe</label>
                <input type="password" formControlName="mot_de_passe" placeholder="Minimum 8 caractères" />
              </div>
              <div class="form-group">
                <label>Confirmer le mot de passe</label>
                <input type="password" formControlName="mot_de_passe_confirmation" placeholder="Répéter le mot de passe" />
              </div>
            </div>
            @if (erreurMdp()) {
              <p style="color:#dc2626;font-size:.85rem;margin:-.5rem 0 .5rem">{{ erreurMdp() }}</p>
            }
          </div>

          <button type="submit" class="btn-primary" [disabled]="loading()">
            {{ loading() ? 'Enregistrement...' : 'Enregistrer les modifications' }}
          </button>
        </form>
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class ProfilComponent implements OnInit {
  private auth = inject(AuthService);
  utilisateur  = this.auth.utilisateur;
  role         = this.auth.role;

  form!: FormGroup;
  loading  = signal(false);
  message  = signal<string | null>(null);
  erreur   = signal<string | null>(null);
  erreurMdp = signal<string | null>(null);

  private readonly ROLES_FR: Record<string, string> = {
    producteur:            'Producteur',
    gestionnaire_entrepot: 'Gestionnaire d\'entrepôt',
    acheteur_gros:         'Acheteur en gros',
    transporteur:          'Transporteur',
    administrateur:        'Administrateur',
  };

  roleFr = () => this.ROLES_FR[this.utilisateur()?.role ?? ''] ?? this.utilisateur()?.role ?? '';

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    const u = this.utilisateur();
    const producteur = u?.producteur;
    const entrepot = u?.entrepot;
    const acheteur = u?.acheteur_gros;
    const transporteur = u?.transporteur;

    this.form = this.fb.group({
      prenom:                   [u?.prenom ?? '',  Validators.required],
      nom:                      [u?.nom    ?? '',  Validators.required],
      email:                    [u?.email  ?? '',  [Validators.required, Validators.email]],
      telephone:                [u?.telephone ?? ''],
      adresse:                  [''],
      mot_de_passe:             [''],
      mot_de_passe_confirmation:[''],

      region:         [producteur?.region ?? ''],
      types_cultures: [producteur?.types_cultures ?? ''],
      superficie:     [producteur?.superficie ?? null],

      nom_entrepot:   [entrepot?.nom_entrepot ?? ''],
      capacite:       [entrepot?.capacite ?? null],
      localisation:   [entrepot?.localisation ?? ''],
      latitude:       [entrepot?.latitude ?? null],
      longitude:      [entrepot?.longitude ?? null],

      type_activite:         [acheteur?.type_activite ?? ''],
      volume_achat_mensuel:  [acheteur?.volume_achat_mensuel ?? null],

      type_vehicule:    [transporteur?.type_vehicule ?? ''],
      capacite_charge:  [transporteur?.capacite_charge ?? null],
      zone:             [transporteur?.zone ?? ''],
    });
  }

  sauvegarder(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }

    const mdp  = this.form.value.mot_de_passe;
    const conf = this.form.value.mot_de_passe_confirmation;
    if (mdp && mdp !== conf) {
      this.erreurMdp.set('Les mots de passe ne correspondent pas.');
      return;
    }
    this.erreurMdp.set(null);

    const data: any = {
      prenom:    this.form.value.prenom,
      nom:       this.form.value.nom,
      email:     this.form.value.email,
      telephone: this.form.value.telephone,
      adresse:   this.form.value.adresse,
    };
    if (mdp) {
      data.mot_de_passe             = mdp;
      data.mot_de_passe_confirmation = conf;
    }

    switch (this.role()) {
      case 'producteur':
        Object.assign(data, {
          region: this.form.value.region,
          types_cultures: this.form.value.types_cultures,
          superficie: this.form.value.superficie,
        });
        break;
      case 'gestionnaire_entrepot':
        Object.assign(data, {
          nom_entrepot: this.form.value.nom_entrepot,
          capacite: this.form.value.capacite,
          localisation: this.form.value.localisation,
          latitude: this.form.value.latitude,
          longitude: this.form.value.longitude,
        });
        break;
      case 'acheteur_gros':
        Object.assign(data, {
          type_activite: this.form.value.type_activite,
          volume_achat_mensuel: this.form.value.volume_achat_mensuel,
        });
        break;
      case 'transporteur':
        Object.assign(data, {
          type_vehicule: this.form.value.type_vehicule,
          capacite_charge: this.form.value.capacite_charge,
          zone: this.form.value.zone,
        });
        break;
    }

    this.loading.set(true);
    this.erreur.set(null);

    this.api.updateProfile(data).subscribe({
      next: res => {
        this.message.set('Profil mis à jour avec succès.');
        this.loading.set(false);
        this.form.get('mot_de_passe')?.reset('');
        this.form.get('mot_de_passe_confirmation')?.reset('');
        this.auth.mettreAJourUtilisateur(res.utilisateur);
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de la mise à jour.');
        this.loading.set(false);
      },
    });
  }
}
