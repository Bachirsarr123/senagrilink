import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe, NgClass } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-productions',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe, NgClass],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Mes productions</h1>
        <button class="btn-primary" (click)="showForm.set(!showForm())">
          {{ showForm() ? '✕ Annuler' : '+ Enregistrer une récolte' }}
        </button>
      </div>

      @if (showForm()) {
        <div class="card form-card">
          <h2>Nouvelle récolte</h2>
          @if (erreur()) { <div class="alert alert-error">{{ erreur() }}</div> }
          @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }

          <form [formGroup]="form" (ngSubmit)="soumettre()">
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
  productions = signal<any[]>([]);
  showForm    = signal(false);
  chargement  = signal(true);
  loading     = signal(false);
  erreur      = signal<string | null>(null);
  succes      = signal<string | null>(null);
  form: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.form = this.fb.group({
      type_culture:     ['', Validators.required],
      superficie:       [null],
      date_recolte:     ['', Validators.required],
      quantite_estimee: [null, [Validators.required, Validators.min(0)]],
      quantite_reelle:  [null],
    });
  }

  ngOnInit(): void {
    this.api.getProductions().subscribe({
      next: res => { this.productions.set(res.productions); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  soumettre(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    this.loading.set(true); this.erreur.set(null);

    this.api.storeProduction(this.form.value).subscribe({
      next: res => {
        this.productions.update(list => [res.production, ...list]);
        this.succes.set('Récolte enregistrée ! Code : ' + res.production.code_tracabilite);
        this.form.reset(); this.loading.set(false);
        setTimeout(() => { this.succes.set(null); this.showForm.set(false); }, 3000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de l\'enregistrement.');
        this.loading.set(false);
      },
    });
  }
}
