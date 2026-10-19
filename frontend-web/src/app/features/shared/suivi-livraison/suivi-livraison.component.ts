import { AfterViewInit, Component, ElementRef, OnDestroy, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { DatePipe, Location } from '@angular/common';
import { ApiService } from '../../../core/services/api.service';
import { LibelleStatutPipe } from '../../../shared/pipes/libelle-statut.pipe';
import { CARTE_SUIVI, CarteSuivi } from '../../../shared/suivi/carte-suivi';
import {
  Point, SEUIL_POSITION_DIRECTE_S, aDesCoordonnees, distanceKm, formaterDistance, libelleAge, versMs,
} from '../../../shared/suivi/geo';

/** Rafraîchissement : le transporteur envoie sa position toutes les 10 s. */
export const DELAI_SUIVI_MS = 10_000;
/** Livraison pas encore partie, ou réseau en échec : on revérifie moins souvent. */
export const DELAI_ATTENTE_MS = 30_000;

/**
 * chargement  : première requête en cours
 * direct      : position reçue (en direct ou ancienne, cf. positionDirecte)
 * sans-position : livraison en cours, aucune position encore envoyée
 * pas-partie  : livraison planifiée, le suivi démarrera au départ
 * terminee    : livrée — suivi arrêté
 * probleme    : problème signalé — suivi arrêté
 * refuse / introuvable : accès refusé ou livraison inconnue — suivi arrêté
 */
export type EtatSuivi = 'chargement' | 'direct' | 'sans-position' | 'pas-partie' | 'terminee' | 'probleme' | 'refuse' | 'introuvable';

@Component({
  selector: 'app-suivi-livraison',
  standalone: true,
  imports: [LibelleStatutPipe, DatePipe, RouterLink],
  template: `
    <div class="suivi" [class.suivi--avec-carte]="aUnePosition()">
      <section class="suivi-carte" aria-label="Carte du suivi de livraison">
        <div #carte class="suivi-carte__fond"></div>

        @if (!aUnePosition()) {
          <div class="suivi-carte__vide">
            @if (etat() === 'chargement') {
              <span class="pastille-chargement" aria-hidden="true"></span>
              <p>Chargement du suivi…</p>
            } @else {
              <span class="suivi-carte__icone" aria-hidden="true">{{ iconeEtat() }}</span>
              <p>{{ messageCarte() }}</p>
            }
          </div>
        } @else {
          <div class="suivi-carte__haut">
            <button type="button" class="bouton-rond" (click)="retour()" aria-label="Retour">←</button>
            <span class="puce-fraicheur" [class.puce-fraicheur--direct]="positionDirecte()" role="status">
              <span class="puce-fraicheur__point" aria-hidden="true"></span>
              {{ positionDirecte() ? 'En direct' : 'Dernière position connue' }} · {{ ageLibelle() }}
            </span>
          </div>
          <div class="suivi-carte__actions">
            <button type="button" class="bouton-rond" (click)="vueEnsemble()" aria-label="Vue d'ensemble du trajet" title="Vue d'ensemble">⤢</button>
            <button type="button" class="bouton-rond" [class.bouton-rond--actif]="suiviAuto()" (click)="centrerVehicule()"
                    aria-label="Centrer sur le transporteur" title="Centrer sur le transporteur">➤</button>
          </div>
          @if (tuilesIndisponibles()) {
            <p class="suivi-carte__avis" role="status">Fond de carte indisponible : les positions restent affichées.</p>
          }
        }
      </section>

      <aside class="suivi-panneau" aria-live="polite">
        <div class="suivi-panneau__poignee" aria-hidden="true"></div>

        @if (statut(); as s) {
          <div class="suivi-panneau__entete">
            <span class="badge badge-{{ s }}">{{ s | libelleStatut }}</span>
            @if (infos()?.numero_livraison; as numero) { <code class="suivi-panneau__numero">{{ numero }}</code> }
          </div>
        }

        @if (aUnePosition()) {
          @if (distanceRestante(); as d) {
            <p class="suivi-panneau__distance"><strong>{{ d }}</strong> jusqu'à la destination</p>
            <p class="suivi-panneau__note">Distance à vol d'oiseau, estimée depuis la dernière position reçue.</p>
          } @else {
            <p class="suivi-panneau__distance"><strong>En route</strong></p>
            <p class="suivi-panneau__note">Coordonnées de destination non renseignées : distance non calculable.</p>
          }
          @if (progression() !== null) {
            <div class="progression" role="progressbar" aria-label="Progression estimée"
                 [attr.aria-valuenow]="progression()" aria-valuemin="0" aria-valuemax="100">
              <span class="progression__barre" [style.width.%]="progression()"></span>
            </div>
          }
        } @else if (etat() !== 'chargement') {
          <!-- L'explication est affichée sur la carte : pas de doublon ici -->
          <p class="suivi-panneau__distance"><strong>{{ titreEtat() }}</strong></p>
        }

        @if (erreurReseau()) {
          <p class="alert alert-error" role="alert">
            Connexion au serveur perdue — nouvelle tentative automatique.
            @if (aUnePosition()) { La position affichée est la dernière reçue. }
          </p>
        }

        @if (infos(); as i) {
          <dl class="suivi-details">
            @if (i.produit) { <div><dt>Marchandise</dt><dd>{{ i.produit }}@if (i.quantite) { · {{ i.quantite }} kg }</dd></div> }
            @if (origineNom()) { <div><dt>Départ</dt><dd>{{ origineNom() }}</dd></div> }
            @if (destinationAdresse()) { <div><dt>Destination</dt><dd>{{ destinationAdresse() }}</dd></div> }
            @if (i.date_depart) { <div><dt>Parti le</dt><dd>{{ i.date_depart | date:'dd/MM/yyyy à HH:mm' }}</dd></div> }
            @if (i.date_livraison) { <div><dt>Livré le</dt><dd>{{ i.date_livraison | date:'dd/MM/yyyy à HH:mm' }}</dd></div> }
            @if (aUnePosition() && horodatagePosition()) {
              <div><dt>Position GPS reçue</dt><dd>{{ horodatagePosition() | date:'dd/MM/yyyy à HH:mm:ss' }}</dd></div>
            }
          </dl>
          @if (i.code_tracabilite) {
            <a class="btn-primary btn-full" [routerLink]="['/tracabilite']" [queryParams]="{ code: i.code_tracabilite }">
              Voir la traçabilité du lot
            </a>
          }
        }

        @if (aUnePosition()) {
          <p class="suivi-legende">
            <span><i class="legende-depart"></i>Départ</span>
            <span><i class="legende-vehicule"></i>Transporteur</span>
            <span><i class="legende-arrivee"></i>Arrivée</span>
            <span><i class="legende-trace"></i>Tracé indicatif</span>
          </p>
        }
      </aside>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss', './suivi-livraison.component.scss'],
})
export class SuiviLivraisonComponent implements OnInit, AfterViewInit, OnDestroy {
  private route = inject(ActivatedRoute);
  private api = inject(ApiService);
  private location = inject(Location);
  private carte: CarteSuivi = inject(CARTE_SUIVI)();

  @ViewChild('carte', { static: true }) private conteneurCarte!: ElementRef<HTMLElement>;

  etat = signal<EtatSuivi>('chargement');
  statut = signal<string | null>(null);
  infos = signal<any | null>(null);
  vehicule = signal<Point | null>(null);
  origine = signal<any | null>(null);
  destination = signal<any | null>(null);
  horodatagePosition = signal<string | null>(null);
  erreurReseau = signal(false);
  tuilesIndisponibles = signal(false);
  suiviAuto = signal(true);

  /** Écart horloge appareil − horloge serveur, pour dater la position sans erreur de fuseau ou d'heure. */
  private decalageHorlogeMs = 0;
  private maintenant = signal(Date.now());
  private minuteur: ReturnType<typeof setTimeout> | null = null;
  private horloge: ReturnType<typeof setInterval> | null = null;
  private livraisonId = 0;
  private carteMontee = false;
  private detruit = false;
  private readonly surVisibilite = () => (document.hidden ? this.annulerMinuteur() : this.rafraichir());

  aUnePosition = computed(() => aDesCoordonnees(this.vehicule()));
  ageSecondes = computed(() => {
    const t = versMs(this.horodatagePosition());
    return t == null ? null : Math.max(0, (this.maintenant() - this.decalageHorlogeMs - t) / 1000);
  });
  positionDirecte = computed(() => {
    const age = this.ageSecondes();
    return this.etat() === 'direct' && !this.erreurReseau() && age != null && age <= SEUIL_POSITION_DIRECTE_S;
  });
  ageLibelle = computed(() => {
    const age = this.ageSecondes();
    return age == null ? 'heure inconnue' : libelleAge(age);
  });
  distanceRestante = computed(() => formaterDistance(distanceKm(this.vehicule() ?? {} as Point, this.destination() ?? {})));
  progression = computed(() => {
    const total = distanceKm(this.origine() ?? {}, this.destination() ?? {});
    const reste = distanceKm(this.vehicule() ?? {} as Point, this.destination() ?? {});
    if (total == null || reste == null || total < 0.05) return null;
    return Math.round(Math.min(100, Math.max(0, (1 - reste / total) * 100)));
  });
  origineNom = computed(() => this.origine()?.nom ?? null);
  destinationAdresse = computed(() => this.destination()?.adresse ?? null);

  titreEtat = computed(() => ({
    'sans-position': 'En attente de position',
    'pas-partie': 'Livraison pas encore partie',
    terminee: 'Livraison effectuée',
    probleme: 'Problème signalé',
    refuse: 'Accès refusé',
    introuvable: 'Livraison introuvable',
  } as Record<string, string>)[this.etat()] ?? '');
  iconeEtat = computed(() => ({
    'sans-position': '📡', 'pas-partie': '🕓', terminee: '✅', probleme: '⚠️', refuse: '🔒', introuvable: '🔍',
  } as Record<string, string>)[this.etat()] ?? '📍');
  messageCarte = computed(() => ({
    'sans-position': 'Le transporteur n’a pas encore transmis sa position. La carte s’affichera dès sa réception.',
    'pas-partie': 'Le suivi en direct démarrera au départ du transporteur. Cette page se met à jour automatiquement.',
    terminee: 'La livraison est terminée : la position du transporteur n’est plus partagée.',
    probleme: 'Le transporteur a signalé un problème : le suivi en direct est suspendu.',
    refuse: 'Vous n’avez pas accès au suivi de cette livraison.',
    introuvable: 'Cette livraison n’existe pas ou n’est plus disponible.',
  } as Record<string, string>)[this.etat()] ?? 'Position indisponible pour le moment.');

  ngOnInit(): void {
    this.livraisonId = Number(this.route.snapshot.paramMap.get('id'));
    this.horloge = setInterval(() => this.maintenant.set(Date.now()), 15_000);
    document.addEventListener('visibilitychange', this.surVisibilite);
  }

  ngAfterViewInit(): void {
    this.rafraichir();
  }

  ngOnDestroy(): void {
    this.detruit = true;
    this.annulerMinuteur();
    if (this.horloge) clearInterval(this.horloge);
    document.removeEventListener('visibilitychange', this.surVisibilite);
    this.carte.detruire();
  }

  retour(): void { this.location.back(); }

  vueEnsemble(): void {
    this.suiviAuto.set(false);
    this.carte.vueEnsemble();
  }

  centrerVehicule(): void {
    this.suiviAuto.set(true);
    this.carte.centrerVehicule();
  }

  rafraichir(): void {
    this.annulerMinuteur();
    if (this.detruit) return;
    this.api.getPositionLivraison(this.livraisonId).subscribe({
      next: res => this.surPosition(res),
      error: err => this.surErreur(err),
    });
  }

  private surPosition(res: any): void {
    const serveur = versMs(res.genere_le);
    this.decalageHorlogeMs = serveur == null ? 0 : Date.now() - serveur;
    this.maintenant.set(Date.now());
    this.erreurReseau.set(false);
    this.statut.set(res.statut_livraison ?? null);
    this.infos.set(res.livraison ?? null);
    this.origine.set(res.origine ?? null);
    this.destination.set(res.destination ?? null);
    this.horodatagePosition.set(res.position?.timestamp ?? null);
    const vehicule = { latitude: res.position?.latitude, longitude: res.position?.longitude };
    this.vehicule.set(aDesCoordonnees(vehicule) ? vehicule : null);
    this.etat.set(this.vehicule() ? 'direct' : 'sans-position');

    if (this.vehicule()) {
      if (!this.carteMontee) {
        this.carte.monter(this.conteneurCarte.nativeElement, ko => this.tuilesIndisponibles.set(ko), () => this.suiviAuto.set(false));
        this.carteMontee = true;
      }
      this.carte.afficher({ vehicule: this.vehicule()!, origine: this.origine() ?? {}, destination: this.destination() ?? {} }, this.suiviAuto());
    }
    this.planifier(DELAI_SUIVI_MS);
  }

  private surErreur(err: any): void {
    const statut = err?.error?.statut_livraison as string | undefined;
    if (err?.status === 0 || err?.status >= 500) {
      // Coupure réseau : la dernière position reste visible, signalée comme ancienne.
      this.erreurReseau.set(true);
      if (this.etat() === 'chargement') this.etat.set('sans-position');
      this.planifier(DELAI_ATTENTE_MS);
      return;
    }
    this.erreurReseau.set(false);
    if (err?.error?.livraison) this.infos.set(err.error.livraison);
    if (statut) this.statut.set(statut);

    // Hors livraison en cours, aucune position n'est exposée : on n'affiche plus l'ancienne.
    if (err?.status === 403) return this.arreter('refuse');
    if (statut === 'livree') return this.arreter('terminee');
    if (statut === 'probleme') return this.arreter('probleme');
    if (statut === 'en_attente') { this.vider(); this.etat.set('pas-partie'); return this.planifier(DELAI_ATTENTE_MS); }
    if (statut === 'en_cours') { this.vider(); this.etat.set('sans-position'); return this.planifier(DELAI_SUIVI_MS); }
    this.arreter('introuvable');
  }

  private arreter(etat: EtatSuivi): void {
    this.vider();
    this.etat.set(etat);
    this.annulerMinuteur();
  }

  private vider(): void {
    this.vehicule.set(null);
    this.horodatagePosition.set(null);
  }

  private planifier(delai: number): void {
    this.annulerMinuteur();
    if (this.detruit || (typeof document !== 'undefined' && document.hidden)) return;
    this.minuteur = setTimeout(() => this.rafraichir(), delai);
  }

  private annulerMinuteur(): void {
    if (this.minuteur) clearTimeout(this.minuteur);
    this.minuteur = null;
  }
}
