import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DatePipe, DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';

@Component({
  selector: 'app-commandes-entrepot',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Commandes à confirmer</h1>
        <span class="subtitle">{{ commandesEnAttente().length }} en attente</span>
      </div>

      @if (message()) {
        <div class="alert alert-success">{{ message() }}</div>
      }
      @if (erreur()) {
        <div class="alert alert-error">{{ erreur() }}</div>
      }

      <!-- Commandes en attente de confirmation -->
      <div class="card">
        <h2>En attente de confirmation</h2>
        @if (chargement()) {
          <p class="loading">Chargement...</p>
        } @else if (commandesEnAttente().length === 0) {
          <p class="empty">Aucune commande en attente.</p>
        } @else {
          <table class="table">
            <thead>
              <tr>
                <th>Numéro</th><th>Produit</th><th>Quantité</th>
                <th>Prix</th><th>Date</th><th>Acheteur</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (c of commandesEnAttente(); track c.id) {
                <tr>
                  <td><code class="code-lot">{{ c.numero_commande }}</code></td>
                  <td><strong>{{ c.produit }}</strong></td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.prix ? (c.prix | number) + ' FCFA' : '—' }}</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>{{ c.acheteur?.prenom }} {{ c.acheteur?.nom }}</td>
                  <td>
                    <div class="btn-group">
                      <button class="btn-success btn-sm"
                              [disabled]="actionEnCours() === c.id"
                              (click)="ouvrirLivraison(c)">
                        Confirmer + Livraison
                      </button>
                      <button class="btn-secondary btn-sm"
                              [disabled]="actionEnCours() === c.id"
                              (click)="confirmerSeul(c.id)">
                        Confirmer seul
                      </button>
                    </div>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Modal création livraison -->
      @if (commandeSelectionnee()) {
        <div class="modal-overlay" (click)="fermerModal()">
          <div class="modal-box" (click)="$event.stopPropagation()">
            <h2>Confirmer &amp; créer la livraison</h2>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1rem">
              Commande : <code class="code-lot">{{ commandeSelectionnee()!.numero_commande }}</code>
              — {{ commandeSelectionnee()!.produit }} / {{ commandeSelectionnee()!.quantite }} kg
            </p>

            @if (erreurModal()) {
              <div class="alert alert-error">{{ erreurModal() }}</div>
            }

            <form [formGroup]="formLivraison" (ngSubmit)="confirmerAvecLivraison()">
              <div class="form-row">
                <div class="form-group">
                  <label>Origine *</label>
                  <input type="text" formControlName="origine" placeholder="Entrepôt, ville..." />
                </div>
                <div class="form-group">
                  <label>Destination *</label>
                  <input type="text" formControlName="destination" placeholder="Adresse livraison..." />
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Date de départ *</label>
                  <input type="datetime-local" formControlName="date_depart" />
                </div>
                <div class="form-group">
                  <label>Date livraison prévue</label>
                  <input type="datetime-local" formControlName="date_livraison" />
                </div>
              </div>
              <div class="btn-group">
                <button type="submit" class="btn-success" [disabled]="loadingModal()">
                  {{ loadingModal() ? 'Traitement...' : 'Confirmer et créer la livraison' }}
                </button>
                <button type="button" class="btn-ghost" (click)="fermerModal()">Annuler</button>
              </div>
            </form>
          </div>
        </div>
      }

      <!-- Commandes déjà confirmées (historique) -->
      <div class="card">
        <h2>Commandes confirmées</h2>
        @if (commandesConfirmees().length === 0) {
          <p class="empty">Aucune commande confirmée.</p>
        } @else {
          <table class="table">
            <thead>
              <tr><th>Numéro</th><th>Produit</th><th>Quantité</th><th>Date</th><th>Livraison</th></tr>
            </thead>
            <tbody>
              @for (c of commandesConfirmees(); track c.id) {
                <tr>
                  <td><code class="code-lot">{{ c.numero_commande }}</code></td>
                  <td>{{ c.produit }}</td>
                  <td>{{ c.quantite | number }} kg</td>
                  <td>{{ c.date_commande | date:'dd/MM/yyyy' }}</td>
                  <td>
                    @if (c.livraison) {
                      <span class="badge badge-{{ c.livraison.statut }}">{{ c.livraison.statut }}</span>
                    } @else {
                      <span class="text-muted">—</span>
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
  styles: [`
    .modal-overlay {
      position:fixed;inset:0;background:rgba(0,0,0,.45);
      display:flex;align-items:center;justify-content:center;z-index:1000;
    }
    .modal-box {
      background:white;border-radius:10px;padding:2rem;
      width:min(560px,94vw);max-height:90vh;overflow-y:auto;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class CommandesEntrepotComponent implements OnInit {
  toutes              = signal<any[]>([]);
  commandesEnAttente  = signal<any[]>([]);
  commandesConfirmees = signal<any[]>([]);
  chargement          = signal(true);
  actionEnCours       = signal<number | null>(null);
  commandeSelectionnee = signal<any | null>(null);
  message             = signal<string | null>(null);
  erreur              = signal<string | null>(null);
  erreurModal         = signal<string | null>(null);
  loadingModal        = signal(false);
  formLivraison!: FormGroup;

  constructor(private api: ApiService, private fb: FormBuilder) {}

  ngOnInit(): void {
    this.formLivraison = this.fb.group({
      origine:        ['', Validators.required],
      destination:    ['', Validators.required],
      date_depart:    ['', Validators.required],
      date_livraison: [''],
    });
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.api.getCommandesPourConfirmation().subscribe({
      next: res => {
        const all: any[] = res.commandes ?? [];
        this.commandesEnAttente.set(all.filter(c => c.statut === 'en_attente'));
        this.commandesConfirmees.set(all.filter(c => c.statut === 'confirmee'));
        this.chargement.set(false);
      },
      error: () => this.chargement.set(false),
    });
  }

  confirmerSeul(id: number): void {
    if (!confirm('Confirmer cette commande sans créer de livraison ?')) return;
    this.actionEnCours.set(id);
    this.erreur.set(null);
    this.api.confirmerCommande(id).subscribe({
      next: () => {
        this.message.set('Commande confirmée. Le stock a été mis à jour.');
        this.actionEnCours.set(null);
        this.charger();
        setTimeout(() => this.message.set(null), 4000);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Erreur lors de la confirmation.');
        this.actionEnCours.set(null);
      },
    });
  }

  ouvrirLivraison(c: any): void {
    this.commandeSelectionnee.set(c);
    this.formLivraison.reset();
    this.erreurModal.set(null);
  }

  fermerModal(): void { this.commandeSelectionnee.set(null); }

  confirmerAvecLivraison(): void {
    if (this.formLivraison.invalid) { this.formLivraison.markAllAsTouched(); return; }
    const c = this.commandeSelectionnee()!;
    this.loadingModal.set(true);
    this.erreurModal.set(null);

    this.api.confirmerCommande(c.id).subscribe({
      next: () => {
        const livData = { ...this.formLivraison.value, commande_id: c.id };
        this.api.createLivraison(livData).subscribe({
          next: () => {
            this.loadingModal.set(false);
            this.fermerModal();
            this.message.set('Commande confirmée et livraison créée avec succès.');
            this.charger();
            setTimeout(() => this.message.set(null), 4000);
          },
          error: err => {
            this.erreurModal.set(err.error?.message ?? 'Commande confirmée mais erreur à la création de la livraison.');
            this.loadingModal.set(false);
          },
        });
      },
      error: err => {
        this.erreurModal.set(err.error?.message ?? 'Erreur lors de la confirmation.');
        this.loadingModal.set(false);
      },
    });
  }
}
