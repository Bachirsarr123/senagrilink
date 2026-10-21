import { Component, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';

@Component({
  selector: 'app-catalogue',
  standalone: true,
  imports: [ReactiveFormsModule, DecimalPipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Catalogue des produits</h1>
        <p class="subtitle">{{ produits().length }} produit(s) disponible(s)</p>
      </div>

      @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }
      @if (erreurCommande()) { <div class="alert alert-error">{{ erreurCommande() }}</div> }

      @if (chargement()) { <p class="loading">Chargement...</p> }
      @else if (erreurChargement()) {
        <div class="alert alert-error">{{ erreurChargement() }}</div>
        <button class="btn-secondary" (click)="chargerCatalogue()">Réessayer</button>
      }
      @else if (produits().length === 0) { <p class="empty">Aucun produit disponible pour le moment.</p> }
      @else {
        <div class="cards-grid">
          @for (p of produits(); track p.stock_id) {
            <div class="product-card">
              <div class="product-header">
                <h3>{{ p.produit }}</h3>
                <span class="badge badge-disponible">Disponible</span>
              </div>
              <div class="product-info">
                <div class="info-row">
                  <span>💰 Prix</span>
                  <strong>{{ p.prix_unitaire | number }} FCFA / kg</strong>
                </div>
                <div class="info-row">
                  <span>📦 Disponible</span>
                  <strong>{{ p.quantite_disponible | number }} kg</strong>
                </div>
                <div class="info-row">
                  <span>🏪 Entrepôt</span>
                  <strong>{{ p.entrepot?.nom_entrepot }}</strong>
                </div>
                <div class="info-row">
                  <span>📍 Localisation</span>
                  <span>{{ p.entrepot?.localisation }}</span>
                </div>
                @if (p.production) {
                  <div class="info-row">
                    <span>🔍 Traçabilité</span>
                    <code>{{ p.production.code_tracabilite }}</code>
                  </div>
                }
              </div>

              @if (commandeOuverte() === p.stock_id) {
                <form [formGroup]="formCommande" (ngSubmit)="passerCommande(p)" class="commande-form">
                  <div class="form-group">
                    <label>Quantité souhaitée (kg, au plus {{ p.quantite_disponible | number }})</label>
                    <input type="number" formControlName="quantite" [max]="p.quantite_disponible" min="0.01" step="any" />
                  </div>
                  @if (montant(p) !== null) {
                    <p class="montant">Total : <strong>{{ montant(p) | number:'1.0-2' }} FCFA</strong>
                      ({{ formCommande.value.quantite | number }} kg × {{ p.prix_unitaire | number }} FCFA)</p>
                  }
                  @if (depasse(p)) {
                    <span class="field-error">Il ne reste que {{ p.quantite_disponible | number }} kg disponibles.</span>
                  }
                  <div class="btn-group">
                    <button type="submit" class="btn-primary" [disabled]="loadingCmd() || depasse(p)">
                      {{ loadingCmd() ? '...' : 'Confirmer la commande' }}
                    </button>
                    <button type="button" class="btn-ghost" (click)="commandeOuverte.set(null)">Annuler</button>
                  </div>
                </form>
              } @else {
                <button class="btn-primary btn-full" (click)="ouvrirCommande(p)">
                  Commander
                </button>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
  styles: [`.montant { margin: .25rem 0 .75rem; font-size: .95rem; }`],
})
export class CatalogueComponent implements OnInit {
  produits        = signal<any[]>([]);
  commandeOuverte = signal<number | null>(null);
  chargement      = signal(true);
  erreurChargement = signal<string | null>(null);
  loadingCmd      = signal(false);
  succes          = signal<string | null>(null);
  erreurCommande  = signal<string | null>(null);
  formCommande: FormGroup;

  /** Clé de la commande en cours de saisie : un nouvel essai ne crée pas de doublon. */
  private cleCommande = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.formCommande = this.fb.group({
      quantite: [null, [Validators.required, Validators.min(0.01)]],
    });
  }

  ngOnInit(): void {
    this.chargerCatalogue();
  }

  chargerCatalogue(): void {
    this.erreurChargement.set(null);
    this.api.getCatalogue().subscribe({
      next: res => { this.produits.set(res.catalogue); this.chargement.set(false); },
      error: err => {
        this.erreurChargement.set(err.status === 0 ? 'Serveur injoignable. Vérifie ta connexion.' : 'Impossible de charger le catalogue.');
        this.chargement.set(false);
      },
    });
  }

  /** Montant calculé au prix du producteur (le serveur fige ce prix à la commande). */
  montant(p: any): number | null {
    const q = Number(this.formCommande.value.quantite);
    return q > 0 ? Math.round(q * p.prix_unitaire * 100) / 100 : null;
  }

  depasse(p: any): boolean {
    return Number(this.formCommande.value.quantite) > p.quantite_disponible;
  }

  ouvrirCommande(produit: any): void {
    this.commandeOuverte.set(produit.stock_id);
    this.formCommande.reset();
    this.cleCommande.renouveler();
    this.erreurCommande.set(null);
  }

  passerCommande(produit: any): void {
    if (this.formCommande.invalid) { this.formCommande.markAllAsTouched(); return; }
    this.loadingCmd.set(true); this.erreurCommande.set(null);

    this.api.passerCommande({ stock_id: produit.stock_id, ...this.formCommande.value }, this.cleCommande.valeur).subscribe({
      next: res => {
        this.cleCommande.renouveler();
        this.succes.set(`Commande ${res.commande.numero_commande} passée : ${res.commande.quantite} kg pour ${res.commande.prix} FCFA. L'entrepôt va la confirmer.`);
        this.commandeOuverte.set(null);
        this.loadingCmd.set(false);
        this.chargerCatalogue();
        setTimeout(() => this.succes.set(null), 4000);
      },
      error: err => {
        this.erreurCommande.set(err.error?.message ?? 'Erreur lors de la commande.');
        this.loadingCmd.set(false);
      },
    });
  }
}
