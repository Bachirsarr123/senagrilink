import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { DatePipe } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { EtapeChronologie, construireChronologie, resumerLot } from '../../../shared/suivi/chronologie';

const ICONES: Record<EtapeChronologie['type'], string> = {
  recolte: '🌱', entree: '🏭', sortie: '📤', commande: '🛒', depart: '🚚', livree: '✅', planifiee: '🗓️', probleme: '⚠️',
};

@Component({
  selector: 'app-tracabilite',
  standalone: true,
  imports: [LibelleStatutPipe, ReactiveFormsModule, DatePipe, RouterLink],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Traçabilité d'un lot</h1>
        <span class="subtitle">Parcours d'un produit, de la récolte à la livraison</span>
      </div>

      <div class="card form-card">
        <form [formGroup]="form" (ngSubmit)="rechercher()" class="recherche">
          <div class="form-group recherche__champ">
            <label for="code-lot">Code de traçabilité *</label>
            <input id="code-lot" type="text" formControlName="code" placeholder="ex : LOT-2026-00001"
                   class="champ-code" autocomplete="off" enterkeyhint="search" />
          </div>
          <button type="submit" class="btn-primary" [disabled]="loading()">
            {{ loading() ? 'Recherche…' : 'Rechercher' }}
          </button>
        </form>
      </div>

      @if (erreur()) { <div class="alert alert-error" role="alert">{{ erreur() }}</div> }
      @if (loading()) { <p class="loading">Recherche du lot…</p> }

      @if (resultat() && resume(); as r) {
        <section class="lot card" aria-label="Résumé du lot">
          <div class="lot__entete">
            <div>
              <p class="lot__surtitre">Lot <code class="code-lot">{{ r.code }}</code></p>
              <h2 class="lot__produit">{{ r.produit ?? 'Produit non renseigné' }}</h2>
              @if (r.producteur) {
                <p class="lot__origine">Produit par <strong>{{ r.producteur }}</strong>@if (r.region) { · {{ r.region }} }</p>
              }
            </div>
            @if (r.etatActuel) { <span class="lot__etat">{{ r.etatActuel }}</span> }
          </div>
          <dl class="lot__faits">
            <div><dt>Dernier lieu enregistré</dt><dd>{{ r.dernierLieu ?? 'Aucun lieu enregistré' }}</dd></div>
            <div><dt>Dernière étape</dt><dd>{{ r.dateDerniereEtape ? (r.dateDerniereEtape | date:'dd/MM/yyyy') : '—' }}</dd></div>
          </dl>
          <p class="lot__note">Les lieux indiqués sont ceux déclarés lors de chaque opération, et non une position GPS.</p>
          @if (r.livraisonEnCoursId && peutSuivreEnDirect()) {
            <a class="btn-primary" [routerLink]="['/suivi-livraison', r.livraisonEnCoursId]">Suivre la livraison sur la carte</a>
          }
        </section>

        <section class="card" aria-label="Chronologie du lot">
          <h2>Chronologie</h2>
          <ol class="chronologie">
            @for (e of etapes(); track e.cle) {
              <li class="etape etape--{{ e.etat }}" [attr.aria-current]="e.etat === 'actuel' ? 'step' : null">
                <span class="etape__point" aria-hidden="true">{{ icone(e) }}</span>
                <div class="etape__corps">
                  <div class="etape__ligne">
                    <h3 class="etape__titre">{{ e.titre }}</h3>
                    @if (e.etat === 'actuel') { <span class="etape__marque">Étape actuelle</span> }
                    @if (e.etat === 'prevu') { <span class="etape__marque etape__marque--prevu">Prévue</span> }
                  </div>
                  <p class="etape__date">
                    @if (e.date) { {{ e.date | date:(aUneHeure(e.date) ? 'dd/MM/yyyy à HH:mm' : 'dd/MM/yyyy') }} }
                    @else if (e.etat === 'prevu') { Date de départ non encore fixée }
                    @else { Date non enregistrée }
                  </p>
                  @if (e.lieu) { <p class="etape__lieu"><span aria-hidden="true">📍</span> {{ e.lieu }}</p> }
                  @if (e.detail) { <p class="etape__detail">{{ e.detail }}</p> }
                  @if (e.statut && e.type !== 'depart' && e.type !== 'livree') {
                    <span class="badge badge-{{ e.statut }}">{{ e.statut | libelleStatut }}</span>
                  }
                  @if (e.livraisonId && peutSuivreEnDirect()) {
                    <a class="btn-secondary btn-sm etape__lien" [routerLink]="['/suivi-livraison', e.livraisonId]">📍 Voir sur la carte</a>
                  }
                </div>
              </li>
            }
          </ol>
          @if (resultat()?.etapes_stockage?.length === 0) {
            <p class="empty">Ce lot n'a pas encore été réceptionné en entrepôt.</p>
          }
        </section>
      }
    </div>
  `,
  styleUrls: ['../../shared.styles.scss', './tracabilite.component.scss'],
})
export class TracabiliteComponent implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private fb = inject(FormBuilder);
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  form: FormGroup = this.fb.group({ code: ['', Validators.required] });
  resultat = signal<any | null>(null);
  loading = signal(false);
  erreur = signal<string | null>(null);

  etapes = computed(() => construireChronologie(this.resultat()));
  resume = computed(() => (this.resultat() ? resumerLot(this.resultat(), this.etapes()) : null));

  // Le suivi en direct n'est accessible qu'au producteur et à l'acheteur concernés (cf. app.routes.ts).
  peutSuivreEnDirect = computed(() => this.auth.role() === 'producteur' || this.auth.role() === 'acheteur_gros');

  ngOnInit(): void {
    // Lien direct depuis la carte de suivi : /tracabilite?code=LOT-…
    const code = this.route.snapshot.queryParamMap.get('code');
    if (code) {
      this.form.setValue({ code });
      this.rechercher();
    }
  }

  icone(e: EtapeChronologie): string { return ICONES[e.type]; }
  aUneHeure(date: string): boolean { return /\d{2}:\d{2}/.test(date); }

  rechercher(): void {
    if (this.form.invalid) { this.form.markAllAsTouched(); return; }
    const code = (this.form.value.code as string).trim().toUpperCase();
    this.loading.set(true);
    this.erreur.set(null);
    this.resultat.set(null);
    // Le code reste dans l'adresse : partage du lien et retour arrière depuis la carte.
    this.router.navigate([], { relativeTo: this.route, queryParams: { code }, replaceUrl: true });

    this.api.getTracabilite(code).subscribe({
      next: res => { this.resultat.set(res.tracabilite); this.loading.set(false); },
      error: err => {
        this.erreur.set(err.status === 0
          ? 'Serveur injoignable. Vérifiez votre connexion puis réessayez.'
          : err.error?.message ?? 'Code introuvable ou erreur serveur.');
        this.loading.set(false);
      },
    });
  }
}
