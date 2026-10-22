import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { DecimalPipe } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { CleSoumission } from '../../../shared/idempotence/cle-soumission';
import { ChoixPositionComponent, Position } from '../../../shared/position/choix-position';
import { PositionAppareilService } from '../../../shared/position/position-appareil';

@Component({
  selector: 'app-catalogue',
  standalone: true,
  imports: [ReactiveFormsModule, DecimalPipe, ChoixPositionComponent],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Catalogue des produits</h1>
        <p class="subtitle">{{ produits().length }} produit(s) disponible(s)</p>
      </div>

      @if (succes()) { <div class="alert alert-success">{{ succes() }}</div> }
      @if (erreurCommande()) { <div class="alert alert-error">{{ erreurCommande() }}</div> }

      <!-- Lot 8 : lieu de livraison pris automatiquement, modifiable sur la carte -->
      <div class="card lieu-livraison" aria-label="Lieu de livraison">
        <div class="lieu-livraison__ligne">
          <span>📍 <strong>Livraison :</strong>
            @if (lieuLivraison()) { {{ lieuOrigine() === 'appareil' ? 'à votre position actuelle' : 'à votre adresse habituelle' }} }
            @else if (rechercheLieu()) { recherche de votre position… }
            @else { lieu à placer sur la carte }</span>
          <button type="button" class="btn-ghost btn-sm" (click)="carteLieuOuverte.set(!carteLieuOuverte())">{{ carteLieuOuverte() ? 'Fermer la carte' : 'Modifier sur la carte' }}</button>
        </div>
        @if (carteLieuOuverte()) {
          <app-choix-position libelle="Où voulez-vous être livré ?" [initiale]="lieuLivraison()" [effacable]="false" (changement)="lieuChoisi($event)" />
        }
      </div>

      @if (panier().length > 0) {
        <div class="card panier" aria-label="Panier">
          <h2>Mon panier <span class="compteur">{{ panier().length }}</span></h2>
          <ul>
            @for (l of panier(); track l.stock_id) {
              <li><span>{{ l.produit }} — {{ l.quantite | number }} kg × {{ l.prix_unitaire | number }} FCFA</span>
                <strong>{{ l.quantite * l.prix_unitaire | number:'1.0-2' }} FCFA</strong>
                <button class="btn-ghost btn-sm" (click)="retirer(l.stock_id)" [attr.aria-label]="'Retirer ' + l.produit">Retirer</button></li>
            }
          </ul>
          <p class="montant">Total du panier : <strong>{{ totalPanier() | number:'1.0-2' }} FCFA</strong></p>
          <p class="note">Tout ou rien : si un produit n'est plus disponible en quantité suffisante, aucune commande n'est passée.</p>
          <button class="btn-primary" [disabled]="loadingCmd()" (click)="commanderPanier()">{{ loadingCmd() ? 'Envoi…' : 'Commander le panier' }}</button>
        </div>
      }

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
                    <button type="button" class="btn-secondary" [disabled]="depasse(p)" (click)="ajouterAuPanier(p)">Ajouter au panier</button>
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
  styles: [`
    .montant { margin: .25rem 0 .75rem; font-size: .95rem; }
    .lieu-livraison { padding:.8rem 1rem; }
    .lieu-livraison__ligne { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:space-between; margin-bottom:.4rem; }
    .panier ul { list-style:none; margin:0 0 .5rem; padding:0; display:grid; gap:.4rem; }
    .panier li { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:space-between; }
    .compteur { display:inline-block; min-width:1.6rem; padding:0 .45rem; border-radius:999px; background:#d8f3dc; color:#1b4332; font-size:.85rem; text-align:center; }
    .note { font-size:.8rem; color:#6b7280; margin:0 0 .75rem; }
  `],
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
  /** Panier : plusieurs produits commandés en une fois (une commande par produit, même référence de panier). */
  panier      = signal<{ stock_id: number; produit: string; quantite: number; prix_unitaire: number }[]>([]);
  totalPanier = computed(() => Math.round(this.panier().reduce((t, l) => t + l.quantite * l.prix_unitaire, 0) * 100) / 100);
  private clePanier = new CleSoumission();

  private readonly appareil = inject(PositionAppareilService);
  lieuLivraison   = signal<Position | null>(null);
  lieuOrigine     = signal<'appareil' | 'habituel' | 'carte' | null>(null);
  rechercheLieu   = signal(false);
  carteLieuOuverte = signal(false);

  /** Lieu habituel, puis position actuelle de l'appareil dès qu'elle arrive. */
  private trouverLieu(): void {
    const habituel = this.appareil.lieuHabituel();
    if (habituel) { this.lieuLivraison.set(habituel); this.lieuOrigine.set('habituel'); }
    this.rechercheLieu.set(true);
    this.appareil.actuelle().then(p => {
      this.rechercheLieu.set(false);
      if (p && this.lieuOrigine() !== 'carte') { this.lieuLivraison.set(p); this.lieuOrigine.set('appareil'); }
    });
  }

  lieuChoisi(p: Position | null): void { if (p) { this.lieuLivraison.set(p); this.lieuOrigine.set('carte'); } }

  /** Lieu de livraison joint à la commande ; sans lieu, le serveur reprend l'adresse habituelle. */
  private lieu(): Record<string, number> {
    const p = this.lieuLivraison();
    return p ? { livraison_latitude: p.latitude, livraison_longitude: p.longitude } : {};
  }

  /** Clé de la commande en cours de saisie : un nouvel essai ne crée pas de doublon. */
  private cleCommande = new CleSoumission();

  constructor(private api: ApiService, private fb: FormBuilder) {
    this.formCommande = this.fb.group({
      quantite: [null, [Validators.required, Validators.min(0.01)]],
    });
  }

  ngOnInit(): void {
    this.chargerCatalogue();
    this.trouverLieu();
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

    this.api.passerCommande({ stock_id: produit.stock_id, ...this.formCommande.value, ...this.lieu() }, this.cleCommande.valeur).subscribe({
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

  ajouterAuPanier(p: any): void {
    if (this.formCommande.invalid) { this.formCommande.markAllAsTouched(); return; }
    const quantite = Number(this.formCommande.value.quantite);
    this.panier.update(l => [...l.filter(x => x.stock_id !== p.stock_id), { stock_id: p.stock_id, produit: p.produit, quantite, prix_unitaire: p.prix_unitaire }]);
    this.clePanier.renouveler();
    this.commandeOuverte.set(null);
  }

  retirer(stockId: number): void {
    this.panier.update(l => l.filter(x => x.stock_id !== stockId));
    this.clePanier.renouveler();
  }

  commanderPanier(): void {
    const lignes = this.panier().map(({ stock_id, quantite }) => ({ stock_id, quantite }));
    if (!confirm(`Commander ${lignes.length} produit(s) pour ${this.totalPanier()} FCFA ?`)) return;
    this.loadingCmd.set(true); this.erreurCommande.set(null);
    this.api.passerCommande({ lignes, ...this.lieu() }, this.clePanier.valeur).subscribe({
      next: res => {
        this.clePanier.renouveler();
        this.panier.set([]);
        this.succes.set(`${res.message} L'entrepôt va les confirmer.`);
        this.loadingCmd.set(false);
        this.chargerCatalogue();
        setTimeout(() => this.succes.set(null), 5000);
      },
      error: err => {
        const ligne = err.error?.produit ? ` (${err.error.produit})` : '';
        this.erreurCommande.set((err.error?.message ?? 'Erreur lors de la commande.') + ligne);
        this.loadingCmd.set(false);
      },
    });
  }
}
