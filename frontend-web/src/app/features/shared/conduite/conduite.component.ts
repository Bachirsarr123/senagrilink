import { AfterViewInit, Component, ElementRef, InjectionToken, OnDestroy, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import * as L from 'leaflet';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/auth/auth.service';
import { executerActionDirecte, libelleActionDirecte } from '../../../shared/action-directe';
import { PositionAppareilService } from '../../../shared/position/position-appareil';
import { PartagePositionService } from '../../../shared/suivi/partage-position';

type Point = [number, number];

/** Carte de conduite isolée du composant, pour être remplacée en test. */
export interface CarteConduite {
  monter(element: HTMLElement): void;
  afficher(moi: Point | null, trajet: Point[], cible: Point | null, routier: boolean, suivre: boolean): void;
  detruire(): void;
}

export const CARTE_CONDUITE = new InjectionToken<() => CarteConduite>('CARTE_CONDUITE', {
  providedIn: 'root',
  factory: () => {
    const appareil = inject(PositionAppareilService);
    return () => new CarteConduiteLeaflet(appareil);
  },
});

class CarteConduiteLeaflet implements CarteConduite {
  private carte: L.Map | null = null;
  private ligne: L.Polyline | null = null;
  private moi: L.CircleMarker | null = null;
  private cible: L.Marker | null = null;
  constructor(private readonly appareil: PositionAppareilService) {}

  monter(element: HTMLElement): void {
    this.carte = L.map(element, { zoomControl: false }).setView([14.69, -17.44], 15);
    this.appareil.fondDeCarte().subscribe(f => {
      if (this.carte) L.tileLayer(f?.tuiles_url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: f?.attribution || '© OpenStreetMap', maxZoom: 19 }).addTo(this.carte);
    });
  }

  afficher(moi: Point | null, trajet: Point[], cible: Point | null, routier: boolean, suivre: boolean): void {
    if (!this.carte) return;
    this.ligne ??= L.polyline([], { color: '#1565c0', weight: 7 }).addTo(this.carte);
    this.ligne.setLatLngs(trajet);
    this.ligne.setStyle({ dashArray: routier ? undefined : '12 8' });
    if (cible) {
      this.cible ??= L.marker(cible, { icon: L.divIcon({ className: 'conduite-cible', html: '📍', iconSize: [36, 36], iconAnchor: [18, 34] }) }).addTo(this.carte);
      this.cible.setLatLng(cible);
    }
    if (moi) {
      this.moi ??= L.circleMarker(moi, { radius: 10, color: '#fff', weight: 3, fillColor: '#1565c0', fillOpacity: 1 }).addTo(this.carte);
      this.moi.setLatLng(moi);
      if (suivre) this.carte.setView(moi, Math.max(this.carte.getZoom(), 16));
    }
  }

  detruire(): void { this.carte?.remove(); this.carte = null; this.ligne = null; this.moi = null; this.cible = null; }
}

/** Distance en mètres entre deux points. */
function metres(a: Point, b: Point): number {
  const r = 6371000, dLat = (b[0] - a[0]) * Math.PI / 180, dLng = (b[1] - a[1]) * Math.PI / 180;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * Math.PI / 180) * Math.cos(b[0] * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
  return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
}

const FLECHES: Record<string, string> = {
  gauche: '⬅', gauche_serre: '⬅', gauche_leger: '↖', droite: '➡', droite_serre: '➡', droite_leger: '↗',
  rond_point: '⟳', demi_tour: '↩', arrivee: '🏁', depart: '⬆', tout_droit: '⬆',
};

/**
 * Page « Conduite » (lot 9), même rôle que l'écran de l'application : chemin
 * par la route, consigne en gros (lue à voix haute si la voix est activée),
 * un seul gros bouton pour l'étape suivante. Tout reste dans SenAgriLink.
 */
@Component({
  selector: 'app-conduite',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div class="conduite">
      <div class="consigne" role="status" aria-live="polite">
        @if (erreur()) { <span>{{ erreur() }}</span> }
        @else if (!nav()) { <span>Recherche de votre position…</span> }
        @else if (!nav().itineraire) { <span class="consigne__texte">{{ nav().message }}</span> }
        @else if (prochaine(); as p) {
          <span class="consigne__fleche" aria-hidden="true">{{ fleche(p.etape.type) }}</span>
          <span>
            @if (p.distance >= 30) { <small>Dans {{ distance(p.distance) }}</small><br> }
            <strong class="consigne__texte">{{ p.etape.instruction }}</strong>
          </span>
        }
        <button type="button" class="voix" (click)="basculerVoix()" [attr.aria-label]="voix() ? 'Couper la voix' : 'Activer la voix'">{{ voix() ? '🔊' : '🔇' }}</button>
      </div>
      <div #carte class="carte" role="application" aria-label="Carte du trajet"></div>
      <div class="bas">
        @if (nav()?.cible; as c) { <p class="but">{{ nav().vers === 'origine' ? 'Prise en charge' : 'Destination' }} : <strong>{{ c.nom }}</strong>{{ c.adresse ? ' — ' + c.adresse : '' }}</p> }
        @if (nav()?.itineraire; as it) { <p class="resume">{{ resume(it) }}</p> }
        @if (nav()?.proche) { <p class="proche">📍 Vous êtes arrivé ?</p> }
        @if (enAttente(); as a) {
          <div class="attente" role="alert">« {{ libelle(a) }} » dans {{ decompte() }} s
            <button type="button" class="btn-secondary btn-sm" (click)="annulerAction()">Annuler</button></div>
        } @else if (action(); as a) {
          <button type="button" class="btn-primary gros" [class.gros--proche]="nav()?.proche" [disabled]="envoi()" (click)="agir(a)">{{ envoi() ? 'Envoi…' : libelle(a) }}</button>
        }
        @if (message()) { <p class="message">{{ message() }}</p> }
        <a class="retour" [routerLink]="retour()">Retour aux missions</a>
      </div>
    </div>
  `,
  styles: [`
    .conduite { display:flex; flex-direction:column; height:calc(100dvh - 64px); }
    .consigne { display:flex; gap:.8rem; align-items:center; background:#0d47a1; color:#fff; padding:.8rem 1rem; font-size:1.15rem; }
    .consigne__fleche { font-size:2.4rem; line-height:1; }
    .consigne__texte { font-size:1.25rem; }
    .consigne small { opacity:.8; font-size:1rem; }
    .voix { margin-left:auto; background:none; border:none; font-size:1.6rem; cursor:pointer; color:#fff; }
    .carte { flex:1; min-height:240px; z-index:0; }
    .bas { padding:.8rem 1rem 1rem; display:grid; gap:.4rem; background:#fff; border-top:1px solid #e5e7eb; }
    .but, .resume, .proche, .message { margin:0; }
    .resume { color:#6b7280; }
    .proche { color:#1b4332; font-weight:700; font-size:1.1rem; }
    .gros { min-height:64px; font-size:1.2rem; font-weight:700; width:100%; }
    .gros--proche { background:#1b4332; }
    .attente { display:flex; gap:.6rem; align-items:center; justify-content:space-between; background:#fef3c7; padding:.8rem; border-radius:8px; font-weight:600; }
    .retour { text-align:center; color:#6b7280; }
  `],
})
export class ConduiteComponent implements OnInit, AfterViewInit, OnDestroy {
  /** Délai pour annuler un appui par erreur (ms), réduit en test. */
  static delaiAnnulationMs = 4000;

  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly partage = inject(PartagePositionService);
  private readonly fabrique = inject(CARTE_CONDUITE);
  private readonly auth = inject(AuthService);
  retour = () => this.auth.role() === 'producteur' ? '/producteur/reservations' : '/transporteur/missions';
  @ViewChild('carte') private conteneur!: ElementRef<HTMLElement>;
  private carte: CarteConduite | null = null;
  private surveillance: number | null = null;
  private dernierAppel = 0;
  private dernierCalcul: Point | null = null;
  private derniereConsigne: string | null = null;
  private prochePrevenu = false;
  private minuteur: ReturnType<typeof setInterval> | null = null;

  type = '';
  id = 0;
  nav = signal<any>(null);
  moi = signal<Point | null>(null);
  erreur = signal<string | null>(null);
  message = signal<string | null>(null);
  envoi = signal(false);
  enAttente = signal<string | null>(null);
  decompte = signal(0);
  voix = signal(this.lireVoix());

  points = computed<Point[]>(() => (this.nav()?.itineraire?.points ?? []).map((p: number[]) => [+p[0], +p[1]] as Point));

  /** Le gros bouton, choisi par le serveur (lot 10) : un seul appui par lieu. */
  action = computed<string | null>(() => this.nav()?.action_principale ?? null);
  private arriveeEnCours = false;

  /** Prochaine consigne et distance jusqu'à elle. */
  prochaine = computed<{ etape: any; distance: number } | null>(() => {
    const etapes: any[] = this.nav()?.itineraire?.etapes ?? [];
    const pts = this.points();
    const moi = this.moi();
    if (!etapes.length || !pts.length || !moi) return null;
    const i = this.indexProche(moi, pts);
    const enCours = etapes.findIndex(e => (+e.fin) > i);
    if (enCours === -1) return { etape: etapes[etapes.length - 1], distance: metres(moi, pts[pts.length - 1]) };
    const distance = metres(moi, pts[Math.min(+etapes[enCours].fin, pts.length - 1)]);
    return { etape: etapes[Math.min(enCours + 1, etapes.length - 1)], distance };
  });

  ngOnInit(): void {
    this.type = this.route.snapshot.paramMap.get('type') ?? '';
    this.id = Number(this.route.snapshot.paramMap.get('id'));
  }

  ngAfterViewInit(): void {
    this.carte = this.fabrique();
    this.carte.monter(this.conteneur.nativeElement);
    if (!('geolocation' in navigator)) { this.erreur.set('Ce navigateur ne donne pas sa position.'); return; }
    this.surveillance = navigator.geolocation.watchPosition(
      p => this.surPosition([p.coords.latitude, p.coords.longitude]),
      e => this.erreur.set(e.code === e.PERMISSION_DENIED ? 'Position refusée : autorisez la localisation pour ce site.' : 'Position momentanément indisponible.'),
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 },
    );
  }

  ngOnDestroy(): void {
    if (this.surveillance !== null) navigator.geolocation.clearWatch(this.surveillance);
    if (this.minuteur) clearInterval(this.minuteur);
    this.carte?.detruire();
    if (typeof speechSynthesis !== 'undefined') speechSynthesis.cancel();
  }

  surPosition(p: Point): void {
    const premier = !this.moi();
    this.moi.set(p);
    const depuis = Date.now() - this.dernierAppel;
    const deplace = this.dernierCalcul ? metres(this.dernierCalcul, p) : Infinity;
    if (premier || (depuis >= 8000 && (deplace > 60 || depuis >= 30000 || this.horsTrajet(p)))) this.calculer();
    this.redessiner();
    this.annoncer();
  }

  private calculer(): void {
    const p = this.moi();
    if (!p) return;
    this.dernierAppel = Date.now();
    this.dernierCalcul = p;
    this.api.getNavigation(this.type, this.id, p[0], p[1]).subscribe({
      next: res => { this.nav.set(res); this.erreur.set(null); this.redessiner(); this.annoncer(); this.arriveeAutomatique(); },
      error: err => {
        if (err.status === 422 && !err.error?.vers) { this.router.navigate([this.retour()]); return; }
        this.erreur.set(err.status === 0 ? 'Serveur injoignable : le trajet se mettra à jour au retour du réseau.' : err.error?.message ?? 'Trajet indisponible.');
      },
    });
  }

  /** Lot 10 : arrivé chez le producteur, l'étape s'enregistre d'elle-même (position GPS). */
  private arriveeAutomatique(): void {
    if (this.arriveeEnCours || !this.nav()?.arrivee_automatique) return;
    this.arriveeEnCours = true;
    executerActionDirecte(this.api, this.type, this.id, 'arriver_collecte').subscribe({
      next: () => {
        this.arriveeEnCours = false;
        this.prochePrevenu = true;
        this.message.set('Arrivée chez le producteur enregistrée.');
        this.dire('Vous êtes arrivé chez le producteur. Chargez la marchandise, puis touchez Démarrer.');
        this.calculer();
      },
      error: () => { this.arriveeEnCours = false; }, // le bouton « Je suis chez le producteur » reste disponible
    });
  }

  private redessiner(): void {
    const c = this.nav()?.cible;
    this.carte?.afficher(this.moi(), this.points(), c?.latitude != null ? [+c.latitude, +c.longitude] : null, this.nav()?.itineraire?.routier !== false, true);
  }

  private indexProche(p: Point, pts: Point[]): number {
    let meilleur = 0, dMin = Infinity;
    pts.forEach((q, i) => { const d = metres(p, q); if (d < dMin) { dMin = d; meilleur = i; } });
    return meilleur;
  }

  private horsTrajet(p: Point): boolean {
    const pts = this.points();
    return pts.length >= 3 && metres(p, pts[this.indexProche(p, pts)]) > 80;
  }

  // ── Voix ──
  private lireVoix(): boolean { try { return localStorage.getItem('conduite_voix') !== 'non'; } catch { return true; } }

  basculerVoix(): void {
    this.voix.set(!this.voix());
    try { localStorage.setItem('conduite_voix', this.voix() ? 'oui' : 'non'); } catch { /* préférence non gardée */ }
    if (!this.voix() && typeof speechSynthesis !== 'undefined') speechSynthesis.cancel();
  }

  dire(texte: string): void {
    if (!this.voix() || typeof speechSynthesis === 'undefined' || typeof SpeechSynthesisUtterance === 'undefined') return;
    const u = new SpeechSynthesisUtterance(texte);
    u.lang = 'fr-FR';
    speechSynthesis.speak(u);
  }

  private annoncer(): void {
    const p = this.prochaine();
    if (p?.etape?.instruction) {
      const cle = `${p.etape.instruction}|${p.distance < 150 ? 'proche' : 'loin'}`;
      if (cle !== this.derniereConsigne) {
        this.derniereConsigne = cle;
        this.dire(p.distance < 30 ? p.etape.instruction : `Dans ${this.distance(p.distance)}, ${p.etape.instruction}`);
      }
    }
    if (this.nav()?.proche && !this.prochePrevenu) { this.prochePrevenu = true; this.dire('Vous êtes arrivé. Touchez le bouton vert pour confirmer.'); }
  }

  // ── Gros bouton, avec quelques secondes pour annuler ──
  agir(action: string): void {
    this.enAttente.set(action);
    this.decompte.set(Math.ceil(ConduiteComponent.delaiAnnulationMs / 1000));
    const fin = Date.now() + ConduiteComponent.delaiAnnulationMs;
    this.minuteur = setInterval(() => {
      const reste = fin - Date.now();
      this.decompte.set(Math.max(0, Math.ceil(reste / 1000)));
      if (reste <= 0) { this.arreterMinuteur(); this.enAttente.set(null); this.executer(action); }
    }, Math.min(250, ConduiteComponent.delaiAnnulationMs));
  }

  annulerAction(): void { this.arreterMinuteur(); this.enAttente.set(null); }

  private arreterMinuteur(): void { if (this.minuteur) clearInterval(this.minuteur); this.minuteur = null; }

  private executer(action: string): void {
    this.envoi.set(true);
    executerActionDirecte(this.api, this.type, this.id, action, undefined, undefined, this.nav()?.actions ?? []).subscribe({
      next: res => {
        this.envoi.set(false);
        this.message.set(res?.message ?? "C'est fait.");
        if (action === 'partir' || action === 'demarrer') this.partage.demarrer({ type: this.type as any, id: this.id, numero: this.nav()?.numero ?? '' });
        if (action === 'arriver' && this.partage.partage(this.type, this.id)) this.partage.arreter();
        this.prochePrevenu = false;
        this.derniereConsigne = null;
        this.calculer();
      },
      error: err => { this.envoi.set(false); this.message.set(err.error?.message ?? 'Action impossible.'); },
    });
  }

  // ── Affichage ──
  libelle(a: string): string { return libelleActionDirecte(a, this.type); }
  fleche(type: string): string { return FLECHES[type] ?? '⬆'; }
  distance(m: number): string { return m >= 1000 ? `${(Math.round(m / 100) / 10).toString().replace('.', ',')} km` : `${Math.round(m / 10) * 10} mètres`; }
  resume(it: any): string {
    const d = it.arrivee_vers ? new Date(it.arrivee_vers) : null;
    const heure = d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '—';
    return `Arrivée vers ${heure} · ${Math.ceil((+it.secondes || 0) / 60)} min · ${String(it.km).replace('.', ',')} km`;
  }
}
