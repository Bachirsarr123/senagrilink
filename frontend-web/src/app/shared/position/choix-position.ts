import { AfterViewInit, Component, ElementRef, InjectionToken, OnDestroy, ViewChild, inject, input, output, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import * as L from 'leaflet';

export interface Position { latitude: number; longitude: number; }

/** Carte de saisie isolée du composant, pour pouvoir être remplacée en test. */
export interface CarteSaisie {
  monter(element: HTMLElement, depart: Position | null, surClic: (p: Position) => void): void;
  placer(p: Position): void;
  detruire(): void;
}

export const CARTE_SAISIE = new InjectionToken<() => CarteSaisie>('CARTE_SAISIE', {
  providedIn: 'root',
  factory: () => () => new CarteSaisieLeaflet(),
});

/** Centre par défaut : Dakar. */
const CENTRE_DEFAUT: L.LatLngTuple = [14.69, -17.44];

class CarteSaisieLeaflet implements CarteSaisie {
  private carte: L.Map | null = null;
  private repere: L.CircleMarker | null = null;

  monter(element: HTMLElement, depart: Position | null, surClic: (p: Position) => void): void {
    this.carte = L.map(element).setView(depart ? [depart.latitude, depart.longitude] : CENTRE_DEFAUT, depart ? 14 : 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(this.carte);
    this.carte.on('click', (e: L.LeafletMouseEvent) => surClic({ latitude: e.latlng.lat, longitude: e.latlng.lng }));
    if (depart) this.placer(depart);
  }

  placer(p: Position): void {
    if (!this.carte) return;
    const point: L.LatLngTuple = [p.latitude, p.longitude];
    this.repere ??= L.circleMarker(point, { radius: 9, color: '#1b4332', fillColor: '#2d6a4f', fillOpacity: .9 }).addTo(this.carte);
    this.repere.setLatLng(point);
    this.carte.panTo(point);
  }

  detruire(): void { this.carte?.remove(); this.carte = null; this.repere = null; }
}

/** Arrondi à 7 décimales (précision de la colonne en base, ~1 cm). */
const arrondir = (v: number) => Math.round(v * 1e7) / 1e7;

/**
 * Position d'un lieu (exploitation, entrepôt, adresse de livraison) : un clic
 * sur la carte, ou la position actuelle de l'appareil. Elle préremplit les
 * missions et permet l'itinéraire du transporteur.
 */
@Component({
  selector: 'app-choix-position',
  standalone: true,
  imports: [DecimalPipe],
  template: `
    <div class="choix-position">
      <div class="entete">
        <span>{{ libelle() }}</span>
        <button type="button" class="btn-secondary btn-sm" (click)="localiser()" [disabled]="recherche()">
          {{ recherche() ? 'Localisation…' : 'Utiliser ma position actuelle' }}
        </button>
      </div>
      <div #carte class="carte" role="application" aria-label="Carte : cliquez pour placer le point"></div>
      @if (position(); as p) {
        <p class="coordonnees">Position enregistrée : {{ p.latitude | number:'1.5-5' }}, {{ p.longitude | number:'1.5-5' }}
          <button type="button" class="lien" (click)="effacer()">Effacer</button></p>
      } @else {
        <p class="coordonnees">Aucune position : cliquez sur la carte à l'endroit exact.</p>
      }
      @if (erreur()) { <p class="erreur">{{ erreur() }}</p> }
    </div>
  `,
  styles: [`
    .entete { display:flex; justify-content:space-between; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:.4rem; font-weight:600; font-size:.9rem; }
    .carte { height:260px; border-radius:8px; border:1px solid #e5e7eb; z-index:0; }
    .coordonnees { font-size:.85rem; color:#6b7280; margin:.4rem 0 0; }
    .erreur { font-size:.85rem; color:#dc2626; margin:.3rem 0 0; }
    .lien { background:none; border:none; color:#2d6a4f; text-decoration:underline; cursor:pointer; padding:0 .3rem; font-size:.85rem; }
  `],
})
export class ChoixPositionComponent implements AfterViewInit, OnDestroy {
  libelle   = input('Position sur la carte');
  initiale  = input<Position | null>(null);
  changement = output<Position | null>();

  position  = signal<Position | null>(null);
  recherche = signal(false);
  erreur    = signal<string | null>(null);

  @ViewChild('carte') private conteneur!: ElementRef<HTMLElement>;
  private readonly fabrique = inject(CARTE_SAISIE);
  private carte: CarteSaisie | null = null;

  ngAfterViewInit(): void {
    const depart = this.initiale();
    this.position.set(depart?.latitude != null && depart?.longitude != null ? depart : null);
    this.carte = this.fabrique();
    this.carte.monter(this.conteneur.nativeElement, this.position(), p => this.definir(p));
  }

  ngOnDestroy(): void { this.carte?.detruire(); }

  definir(p: Position): void {
    const arrondie = { latitude: arrondir(p.latitude), longitude: arrondir(p.longitude) };
    this.position.set(arrondie);
    this.erreur.set(null);
    this.carte?.placer(arrondie);
    this.changement.emit(arrondie);
  }

  effacer(): void {
    this.position.set(null);
    this.changement.emit(null);
  }

  localiser(): void {
    if (!('geolocation' in navigator)) { this.erreur.set('Cet appareil ne donne pas sa position : cliquez sur la carte.'); return; }
    this.recherche.set(true);
    navigator.geolocation.getCurrentPosition(
      pos => { this.recherche.set(false); this.definir({ latitude: pos.coords.latitude, longitude: pos.coords.longitude }); },
      err => {
        this.recherche.set(false);
        this.erreur.set(err.code === err.PERMISSION_DENIED
          ? 'Accès à la position refusé : autorisez-le dans le navigateur, ou cliquez sur la carte.'
          : 'Position introuvable pour le moment : cliquez sur la carte.');
      },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  }
}
