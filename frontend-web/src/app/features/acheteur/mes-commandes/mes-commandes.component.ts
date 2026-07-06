import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-mes-commandes',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header"><h1>Mes commandes</h1></div>

      @if (message()) { <div class="alert alert-success">{{ message() }}</div> }
      @if (erreur())  { <div class="alert alert-error">{{ erreur() }}</div> }

      <div class="card">
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (commandes().length === 0) {
          <p class="empty">Aucune commande passée.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Numéro</th><th>Produit</th><th>Qté</th>
                <th>Prix</th><th>Date</th><th>Statut</th><th>Livraison</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandes(); track c.id) {
                <tr>
                  <td><code class="code-lot">{{ c.numero_commande }}</code></td>
                  <td>{{ c.produit }}</td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix ? (c.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>
                    <span class="badge badge-{{ c.statut }}">{{ c.statut }}</span>
                    @if (c.alerte_livraison) {
                      <span class="badge badge-probleme" title="Problème de livraison signalé">⚠️</span>
                    }
                  </td>
                  <td>
                    @if (c.livraison) {
                      <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut }}</span>
                      @if (c.livraison.statut === 'en_cours') {
                        <a [routerLink]="['/suivi-livraison', c.livraison.id]" class="btn-secondary btn-sm" style="margin-left:.4rem">
                          📍 Suivre
                        </a>
                      }
                    } @else {
                      <span class="text-muted">—</span>
                    }
                  </td>
                  <td>
                    @if (c.statut === 'en_attente') {
                      <div class="btn-group">
                        <button class="btn-secondary btn-sm" (click)="ouvrirModifier(c)">Modifier</button>
                        <button class="btn-danger btn-sm"
                                [disabled]="actionEnCours() === c.id"
                                (click)="annuler(c.id)">
                          Annuler
                        </button>
                      </div>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Modal modification commande -->
      @if (commandeEnEdition()) {
        <div class="modal-overlay" (click)="fermerModal()">
          <div class="modal-box" (click)="$event.stopPropagation()">
            <h2>Modifier la commande</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              <code class="code-lot">{{ commandeEnEdition()!.numero_commande }}</code>
              — {{ commandeEnEdition()!.produit }}
            </p>
            @if (erreurModal()) {
              <div class="alert alert-error">{{ erreurModal() }}</div>
            }
            <form [formGroup]="formModif" (ngSubmit)="sauvegarderModif()">
              <div class="form-row">
                <div class="form-group">
                  <label>Nouvelle quantité (kg) *</label>
                  <input type="number" formControlName="quantite" min="0.01" step="0.01" />
                </div>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-primary" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Enregistrement...' : 'Enregistrer' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModal()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .modal-overlay {
      position:fixed;inset:0;background:rgba(0,0,0,.45);
      display:flex;align-items:center;justify-content:center;z-index:1000;
    }
    .modal-box {
      background:white;border-radius:10px;padding:2rem;
      width:min(420px,94vw);box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class MesCommandesComponent implements OnInit {
  commandes         = signal<any[]>([]);
  chargement        = signal(true);
  actionEnCours     = signal<number | null>(null);
  commandeEnEdition = signal<any | null>(null);
  loadingModal      = signal(false);
  message           = signal<string | null>(null);
  erreur            = signal<string | null>(null);
  erreurModal       = signal<string | null>(null);
  formModif!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formModif = this.fb.group({
      quantite: [null, [Validators.required, Validators.min(0.01)]],
    });
    this.charger();
  }

  charger(): void {
    this.api.getHistoriqueCommandes().subscribe({
      next: res => { this.commandes.set(res.commandes); this.chargement.set(false); },
      error: () => this.chargement.set(false),
    });
  }

  annuler(id: number): void {
    if (!confirm('Confirmer l\'annulation de cette commande ?')) return;
    this.actionEnCours.set(id);
    this.api.annulerCommande(id).subscribe({
      next: () => {
        this.commandes.update(list => list.map(c => c.id === id ? { ...c, statut: 'annulee' } : c));
        this.actionEnCours.set(null);
        this.message.set('Commande annulée.');
        setTimeout(() => this.message.set(null), 3000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de l\'annulation.');
        this.actionEnCours.set(null);
      },
    });
  }

  ouvrirModifier(c: any): void {
    this.commandeEnEdition.set(c);
    this.formModif.reset({ quantite: c.quantite });
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.commandeEnEdition.set(null); }

  sauvegarderModif(): void {
    if (this.formModif.invalid) { this.formModif.markAllAsTouched(); return; }
    const c = this.commandeEnEdition()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    this.api.updateCommande(c.id, this.formModif.value).subscribe({
      next: res => {
        this.commandes.update(list => list.map(x => x.id === c.id ? { ...x, ...res.commande } : x));
        this.loadingModal.set(false);
        this.fermerModal();
        this.message.set('Commande mise à jour.');
        setTimeout(() => this.message.set(null), 3000);
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la modification.');
        this.loadingModal.set(false);
      },
    });
  }
}
