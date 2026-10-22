import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { TableauResponsiveDirective } from '../../../shared/directives/tableau-responsive.directive';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { abandonConfirme, demanderMotif } from '../../../shared/confirmation/confirmation';

@Component({
  selector: 'app-productions',
  standalone: true,
  imports: [LibelleStatutPipe, TableauResponsiveDirective, ReactiveFormsModule, DatePipe, DecimalPipe],
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
            <div class="form-row">
              <div class="form-group">
                <label>Prix de vente (FCFA / kg) *</label>
                <input type="number" formControlName="prix_unitaire" placeholder="Ex. : 250" min="1" step="any" />
                @if (formCreation.get('prix_unitaire')?.invalid && formCreation.get('prix_unitaire')?.touched) {
                  <span class="field-error">Indique ton prix au kilo (au moins 1 FCFA). Les acheteurs verront ce prix.</span>
                }
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

          @if (estVerrouille(productionEnEdition()!)) {
            <div class="alert alert-info">Ce lot est en entrepôt : ses informations sont figées. Seul le prix au kilo peut encore changer
              (les commandes déjà passées gardent leur prix).</div>
          }
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
            <div class="form-row">
              <div class="form-group">
                <label>Prix de vente (FCFA / kg) *</label>
                <input type="number" formControlName="prix_unitaire" min="1" step="any" />
              </div>
            </div>
            <div class="btn-group">
              <button type="submit" class="btn-primary" [disabled]="loadingEdition()">
                {{ loadingEdition() ? 'Enregistrement...' : 'Sauvegarder' }}
              </button>
              <button type="button" class="btn-ghost" (click)="abandonnerEdition()">Annuler</button>
            </div>
          </form>
        </div>
      }

      <!-- Liste des productions -->
      <div class="card">
        @if (erreurListe()) { <div class="alert alert-error" role="alert">{{ erreurListe() }}</div> }
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (productions().length === 0) {
          <p class="empty">Aucune production enregistrée.</p>
        } @else {
          <div class="table-scroll"><table class="table" appTableauResponsive>
            <thead>
              <tr>
                <th>Code traçabilité</th>
                <th>Culture</th>
                <th>Date récolte</th>
                <th>Qté estimée</th>
                <th>Qté réelle</th>
                <th>Prix / kg</th>
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
                  <td>{{ p.prix_unitaire ? (p.prix_unitaire | number) + ' FCFA' : 'À fixer' }}</td>
                  <td><span class="badge badge-{{ p.statut }}">{{ p.statut | libelleStatut }}</span></td>
                  <td>
                    <div class="btn-group">
                      @if (p.statut === 'en_attente' || p.statut === 'disponible') {
                        <button class="btn-secondary btn-sm" (click)="ouvrirEdition(p)">{{ estVerrouille(p) ? 'Modifier le prix' : 'Modifier' }}</button>
                      }
                      @if (p.statut === 'en_attente') {
                        <button class="btn-ghost btn-sm" [disabled]="actionEnCours() === p.id" (click)="annulerRecolte(p)">Annuler la récolte</button>
                      }
                    </div>
                  </td>
                </tr>
              }
            </tbody>
          </table></div>
        }
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
  styles: [`.alert-info { background: #eff6ff; color: #1e3a8a; border-left: 3px solid #3b82f6; }`],
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
  erreurListe       = signal<string | null>(null);
  actionEnCours     = signal<number | null>(null);
  succesEdition     = signal<string | null>(null);
  formCreation!: FormGroup;
  formEdition!: FormGroup;

  private cleRecolte = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formCreation = this.fb.group({
      type_culture:     ['', Validators.required],
      superficie:       [null],
      date_recolte:     ['', Validators.required],
      quantite_estimee: [null, [Validators.required, Validators.min(0)]],
      quantite_reelle:  [null],
      prix_unitaire:    [null, [Validators.required, Validators.min(1)]],
    });
    this.formEdition = this.fb.group({
      type_culture:     ['', Validators.required],
      superficie:       [null],
      date_recolte:     ['', Validators.required],
      quantite_estimee: [null, [Validators.required, Validators.min(0)]],
      quantite_reelle:  [null],
      prix_unitaire:    [null, [Validators.required, Validators.min(1)]],
    });
    this.api.getProductions().subscribe({
      next: res => { this.productions.set(res.productions); this.chargement.set(false); },
      error: err => {
        this.chargement.set(false);
        this.erreurListe.set(err.status === 0 ? 'Serveur injoignable. Vérifiez votre connexion.' : 'Impossible de charger vos productions.');
      },
    });
  }

  toggleFormCreation(): void {
    // Fermeture du formulaire : confirmation si une saisie serait perdue.
    if (this.showFormCreation() && !abandonConfirme(this.formCreation)) return;
    if (!this.showFormCreation() && this.productionEnEdition() && !abandonConfirme(this.formEdition)) return;
    if (this.showFormCreation()) this.formCreation.reset();
    else this.cleRecolte.renouveler(); // une clé par ouverture du formulaire
    this.showFormCreation.set(!this.showFormCreation());
    this.annulerEdition();
  }

  abandonnerEdition(): void {
    if (abandonConfirme(this.formEdition)) this.annulerEdition();
  }

  annulerRecolte(p: any): void {
    const motif = demanderMotif(`Annuler la récolte ${p.code_tracabilite} (${p.type_culture}) ?\nElle restera visible avec le statut « Annulée » mais ne pourra plus être modifiée ni réservée en entrepôt.`, false);
    if (motif === null) return;
    this.actionEnCours.set(p.id);
    this.erreurListe.set(null);
    this.api.annulerProduction(p.id, motif || undefined).subscribe({
      next: res => {
        this.productions.update(list => list.map(x => x.id === p.id ? { ...x, ...res.production } : x));
        this.actionEnCours.set(null);
      },
      error: err => {
        this.erreurListe.set(err.status === 0 ? 'Serveur injoignable : récolte non annulée.' : err.error?.message ?? 'Annulation impossible.');
        this.actionEnCours.set(null);
      },
    });
  }

  soumettre(): void {
    if (this.formCreation.invalid) { this.formCreation.markAllAsTouched(); return; }
    this.loading.set(true);
    this.erreur.set(null);

    this.api.storeProduction(this.formCreation.value, this.cleRecolte.valeur).subscribe({
      next: res => {
        this.cleRecolte.renouveler();
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

  /** Lot entré en entrepôt : seul son prix reste modifiable (règle du serveur). */
  estVerrouille(p: any): boolean {
    return p.statut === 'disponible' || (p.stocks?.length ?? 0) > 0;
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
      prix_unitaire:    p.prix_unitaire,
    });
    for (const champ of ['type_culture', 'superficie', 'date_recolte', 'quantite_estimee', 'quantite_reelle']) {
      const controle = this.formEdition.get(champ)!;
      this.estVerrouille(p) ? controle.disable() : controle.enable();
    }
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
