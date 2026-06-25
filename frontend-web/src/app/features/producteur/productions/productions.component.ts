import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-productions',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Mes productions</h1>
        <button class="btn-primary" (click)="toggleFormCreation()">
          {{ showFormCreation() ? '✕ Annuler' : '+ Enregistrer une récolte' }}
        </button>
      </div>

      <!-- Formulaire de création -->
      @if (showFormCreation()) {
        <div class="card form-card">
          <h2>Nouvelle récolte</h2>
          @if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
          @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }

          <form [formGroup]="formCreation" (ngSubmit)="soumettre()">
            <div class="form-row">
              <div class="form-group">
                <label>Type de culture *</label>
                <input type="text" formControlName="type_culture" placeholder="Mil, Arachide, Maïs..." />
              </div>
              <div class="form-group">
                <label>Superficie (ha)</label>
                <input type="number" formControlName="superficie" placeholder="0" min="0" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Date de récolte *</label>
                <input type="date" formControlName="date_recolte" />
              </div>
              <div class="form-group">
                <label>Quantité estimée (kg) *</label>
                <input type="number" formControlName="quantite_estimee" placeholder="0" min="0" />
              </div>
              <div class="form-group">
                <label>Quantité réelle (kg)</label>
                <input type="number" formControlName="quantite_reelle" placeholder="0" min="0" />
              </div>
            </div>
            <button type="submit" class="btn-primary" [disabled]="loading()">
              {{ loading() ? 'Enregistrement...' : 'Enregistrer la récolte' }}
            </button>
          </form>
        </div>
      }

      <!-- Formulaire d'édition -->
      @if (productionEnEdition()) {
        <div class="card form-card" style="border-left-color:#f59e0b">
          <h2>Modifier la production — <code class="code-lot">{{ productionEnEdition()!.code_tracabilite }}</code></h2>
          @if (erreurEdition()) { <div class="alert alert-error">{{ erreurEdition() }}</div> }
          @if (succesEdition()) { <div class="alert alert-success">{{ succesEdition() }}</div> }

          <form [formGroup]="formEdition" (ngSubmit)="sauvegarderEdition()">
            <div class="form-row">
              <div class="form-group">
                <label>Type de culture *</label>
                <input type="text" formControlName="type_culture" />
              </div>
              <div class="form-group">
                <label>Superficie (ha)</label>
                <input type="number" formControlName="superficie" min="0" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Date de récolte *</label>
                <input type="date" formControlName="date_recolte" />
              </div>
              <div class="form-group">
                <label>Quantité estimée (kg) *</label>
                <input type="number" formControlName="quantite_estimee" min="0" />
              </div>
              <div class="form-group">
                <label>Quantité réelle (kg)</label>
                <input type="number" formControlName="quantite_reelle" min="0" />
              </div>
            </div>
            <div class="btn-group">
              <button type="submit" class="btn-primary" [disabled]="loadingEdition()">
                {{ loadingEdition() ? 'Enregistrement...' : 'Sauvegarder' }}
              </button>
              <button type="button" class="btn-ghost" (click)="annulerEdition()">Annuler</button>
            </div>
          </form>
        </div>
      }

      <!-- Liste des productions -->
      <div class="card">
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (productions().length === 0) {
          <p class="empty">Aucune production enregistrée.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Code traçabilité</th>
                <th>Culture</th>
                <th>Date récolte</th>
                <th>Qté estimée</th>
                <th>Qté réelle</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (p of productions(); track p.id) {
                <tr>
                  <td><code class="code-lot">{{ p.code_tracabilite }}</code></td>
                  <td>{{ p.type_culture }}</td>
                  <td>{{ p.date_recolte | date:'dd/MM/yyyy' }}</td>
                  <td>{{ p.quantite_estimee | number }} kg</td>
                  <td>{{ p.quantite_reelle ? (p.quantite_reelle | number) + ' kg' : '—' }}</td>
                  <td><span class="badge badge-{{ p.statut }}">{{ p.statut }}</span></td>
                  <td>
                    @if (p.statut === 'en_attente' || p.statut === 'disponible') {
                      <button class="btn-secondary btn-sm" (click)="ouvrirEdition(p)">Modifier</button>
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
export class ProductionsComponent implements OnInit {
  productions       = signal<any[]>([]);
  showFormCreation  = signal(false);
  productionEnEdition = signal<any | null>(null);
  chargement        = signal(true);
  loading           = signal(false);
  loadingEdition    = signal(false);
  erreur            = signal<string | null>(null);
  succes            = signal<string | null>(null);
  erreurEdition     = signal<string | null>(null);
  succesEdition     = signal<string | null>(null);
  formCreation!: FormGroup;
  formEdition!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formCreation = this.fb.group({
      type_culture:     ['', Validators.required],
      superficie:       [null],
      date_recolte:     ['', Validators.required],
      quantite_estimee: [null, [Validators.required, Validators.min(0)]],
      quantite_reelle:  [null],
    });
    this.formEdition = this.fb.group({
      type_culture:     ['', Validators.required],
      superficie:       [null],
      date_recolte:     ['', Validators.required],
      quantite_estimee: [null, [Validators.required, Validators.min(0)]],
      quantite_reelle:  [null],
    });
    this.api.getProductions().subscribe({
      next: res => { this.productions.set(res.productions); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  toggleFormCreation(): void {
    this.showFormCreation.set(!this.showFormCreation());
    this.annulerEdition();
  }

  soumettre(): void {
    if (this.formCreation.invalid) { this.formCreation.markAllAsTouched(); return; }
    this.loading.set(true);
    this.erreur.set(null);

    this.api.storeProduction(this.formCreation.value).subscribe({
      next: res => {
        this.productions.update(list => [res.production, ...list]);
        this.succes.set('Récolte enregistrée ! Code : ' + res.production.code_tracabilite);
        this.formCreation.reset();
        this.loading.set(false);
        setTimeout(() => { this.succes.set(null); this.showFormCreation.set(false); }, 3000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de l\'enregistrement.');
        this.loading.set(false);
      },
    });
  }

  ouvrirEdition(p: any): void {
    this.productionEnEdition.set(p);
    this.showFormCreation.set(false);
    this.erreurEdition.set(null);
    this.succesEdition.set(null);
    this.formEdition.reset({
      type_culture:     p.type_culture ?? '',
      superficie:       p.superficie,
      date_recolte:     p.date_recolte ?? '',
      quantite_estimee: p.quantite_estimee,
      quantite_reelle:  p.quantite_reelle,
    });
  }

  annulerEdition(): void { this.productionEnEdition.set(null); }

  sauvegarderEdition(): void {
    if (this.formEdition.invalid) { this.formEdition.markAllAsTouched(); return; }
    const p = this.productionEnEdition()!;
    this.loadingEdition.set(true);
    this.erreurEdition.set(null);

    this.api.updateProduction(p.id, this.formEdition.value).subscribe({
      next: res => {
        this.productions.update(list => list.map(x => x.id === p.id ? { ...x, ...res.production } : x));
        this.succesEdition.set('Production mise à jour.');
        this.loadingEdition.set(false);
        setTimeout(() => { this.succesEdition.set(null); this.annulerEdition(); }, 2000);
      },
      error: err => {
        this.erreurEdition.set(err.error?.message ?? 'Erreur lors de la modification.');
        this.loadingEdition.set(false);
      },
    });
  }
}
