import { Injectable, InjectionToken } from '@angular/core';
import * as L from 'leaflet';
import { Point, aDesCoordonnees } from './geo';

export interface PointsSuivi {
  vehicule: Point;
  origine: Point;
  destination: Point;
}

/** Carte de suivi : isolée du composant pour pouvoir être remplacée en test. */
export interface CarteSuivi {
  monter(element: HTMLElement, surTuilesIndisponibles: (ko: boolean) => void, surDeplacementManuel: () => void): void;
  afficher(points: PointsSuivi, suivreVehicule: boolean): void;
  vueEnsemble(): void;
  centrerVehicule(): void;
  detruire(): void;
}

export const CARTE_SUIVI = new InjectionToken<() => CarteSuivi>('CARTE_SUIVI', {
  providedIn: 'root',
  factory: () => () => new CarteSuiviLeaflet(),
});

const VERT = '#2d6a4f';
const VERT_FONCE = '#1b4332';

/** Marqueurs en HTML (pas d'images à charger) : départ, arrivée, véhicule. */
function marqueur(html: string, taille: number): L.DivIcon {
  return L.divIcon({ html, className: 'marqueur-suivi', iconSize: [taille, taille], iconAnchor: [taille / 2, taille / 2] });
}
const ICONE_DEPART = marqueur(
  `<span style="display:block;width:16px;height:16px;border-radius:50%;background:#fff;border:4px solid ${VERT_FONCE};box-shadow:0 1px 4px rgba(0,0,0,.35)"></span>`, 16);
const ICONE_ARRIVEE = L.divIcon({
  className: 'marqueur-suivi', iconSize: [34, 42], iconAnchor: [17, 40],
  html: `<span style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.3);font-size:18px">🏁</span>`,
});
const ICONE_VEHICULE = marqueur(
  `<span style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background:${VERT};border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.35);font-size:20px">🚚</span>`, 40);

@Injectable()
export class CarteSuiviLeaflet implements CarteSuivi {
  private carte: L.Map | null = null;
  private vehicule: L.Marker | null = null;
  private origine: L.Marker | null = null;
  private destination: L.Marker | null = null;
  private parcouruBord: L.Polyline | null = null;
  private parcouru: L.Polyline | null = null;
  private restant: L.Polyline | null = null;
  private points: PointsSuivi | null = null;

  monter(element: HTMLElement, surTuilesIndisponibles: (ko: boolean) => void, surDeplacementManuel: () => void): void {
    this.carte = L.map(element, { zoomControl: false, attributionControl: true }).setView([14.69, -17.44], 12);
    L.control.zoom({ position: 'bottomright' }).addTo(this.carte);
    const tuiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap', maxZoom: 19,
    }).addTo(this.carte);
    // Une tuile en échec n'empêche pas l'affichage des marqueurs et du panneau.
    tuiles.on('tileerror', () => surTuilesIndisponibles(true));
    tuiles.on('tileload', () => surTuilesIndisponibles(false));
    this.carte.on('dragstart', () => surDeplacementManuel());
  }

  afficher(points: PointsSuivi, suivreVehicule: boolean): void {
    if (!this.carte) return;
    const premierAffichage = this.points === null;
    this.points = points;

    this.vehicule = this.placer(this.vehicule, points.vehicule, ICONE_VEHICULE, 'Dernière position du transporteur', 1000);
    this.origine = this.placer(this.origine, points.origine, ICONE_DEPART, 'Départ : entrepôt', 500);
    this.destination = this.placer(this.destination, points.destination, ICONE_ARRIVEE, 'Arrivée : adresse de livraison', 600);

    // Tracé indicatif à vol d'oiseau (aucun calcul d'itinéraire routier n'est disponible) :
    // trait plein jusqu'au véhicule, pointillés jusqu'à la destination.
    const parcouru = [points.origine, points.vehicule].filter(aDesCoordonnees).map(p => [p.latitude, p.longitude] as L.LatLngTuple);
    const restant = [points.vehicule, points.destination].filter(aDesCoordonnees).map(p => [p.latitude, p.longitude] as L.LatLngTuple);
    this.parcouruBord = this.ligne(this.parcouruBord, parcouru, { color: VERT_FONCE, weight: 9, opacity: .35 });
    this.parcouru = this.ligne(this.parcouru, parcouru, { color: '#40916c', weight: 6 });
    this.restant = this.ligne(this.restant, restant, { color: VERT, weight: 4, dashArray: '2 10', lineCap: 'round' });

    if (premierAffichage) this.vueEnsemble();
    else if (suivreVehicule) this.centrerVehicule(false);
  }

  vueEnsemble(): void {
    if (!this.carte || !this.points) return;
    const tous = [this.points.vehicule, this.points.origine, this.points.destination]
      .filter(aDesCoordonnees).map(p => [p.latitude, p.longitude] as L.LatLngTuple);
    if (tous.length > 1) this.carte.fitBounds(L.latLngBounds(tous), { padding: [48, 48], maxZoom: 15 });
    else if (tous.length === 1) this.carte.setView(tous[0], 14);
  }

  centrerVehicule(zoomer = true): void {
    const v = this.points?.vehicule;
    if (!this.carte || !aDesCoordonnees(v)) return;
    if (zoomer) this.carte.setView([v.latitude, v.longitude], Math.max(this.carte.getZoom(), 15));
    else this.carte.panTo([v.latitude, v.longitude]);
  }

  detruire(): void {
    this.carte?.remove();
    this.carte = null;
  }

  private placer(m: L.Marker | null, p: Point, icon: L.DivIcon, titre: string, z: number): L.Marker | null {
    if (!this.carte) return m;
    if (!aDesCoordonnees(p)) { m?.remove(); return null; }
    if (m) { m.setLatLng([p.latitude, p.longitude]); return m; }
    return L.marker([p.latitude, p.longitude], { icon, title: titre, alt: titre, zIndexOffset: z, keyboard: false })
      .bindTooltip(titre).addTo(this.carte);
  }

  private ligne(l: L.Polyline | null, pts: L.LatLngTuple[], options: L.PolylineOptions): L.Polyline | null {
    if (!this.carte) return l;
    if (pts.length < 2) { l?.remove(); return null; }
    if (l) { l.setLatLngs(pts); return l; }
    return L.polyline(pts, { ...options, interactive: false }).addTo(this.carte);
  }
}
