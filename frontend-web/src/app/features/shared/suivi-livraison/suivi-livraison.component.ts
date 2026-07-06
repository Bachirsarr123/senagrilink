import { Component, OnDestroy, OnInit, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { DatePipe } from '@angular/common';
import * as L from 'leaflet';
import { ApiService } from '../../../core/services/api.service';

const INTERVALLE_RAFRAICHISSEMENT_MS = 10000;

interface PointCarte {
  latitude: number | null;
  longitude: number | null;
}

@Component({
  selector: 'app-suivi-livraison',
  standalone: true,
  imports: [DatePipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Suivi de livraison en direct</h1>
        <span class="subtitle">Position actualisée toutes les 10 secondes</span>
      </div>

      @if (erreur()) {
        <div class="alert alert-error">{{ erreur() }}</div>
      }

      @if (statutLivraison()) {
        <div class="card">
          <div class="stat-row">
            <span>Statut de la livraison</span>
            <span class="badge badge-{{ statutLivraison() }}">{{ statutLivraison() }}</span>
          </div>
          @if (derniereMiseAJour()) {
            <div class="stat-row">
              <span>Dernière position reçue</span>
              <span>{{ derniereMiseAJour() | date:'dd/MM/yyyy HH:mm:ss' }}</span>
            </div>
          }
        </div>
      }

      <div class="card">
        @if (!position()) {
          <p class="empty">{{ erreur() ? 'Position indisponible.' : 'Chargement de la position...' }}</p>
        } @else {
          <div id="suivi-map" style="height: 420px; border-radius: 8px;"></div>
          <p class="legende">
            <span>🏭 Entrepôt</span>
            <span>🚚 Transporteur</span>
            <span>🏠 Adresse de livraison</span>
          </p>
        }
      </div>
    </div>
  `,
  styles: [`
    .legende { display: flex; gap: 1.5rem; margin-top: .75rem; font-size: .85rem; color: #6b7280; }
  `],
  styleUrls: ['../../shared.styles.scss'],
})
export class SuiviLivraisonComponent implements OnInit, OnDestroy {
  position          = signal<{ latitude: number; longitude: number } | null>(null);
  statutLivraison   = signal<string | null>(null);
  derniereMiseAJour = signal<string | null>(null);
  erreur            = signal<string | null>(null);

  private livraisonId!: number;
  private map: L.Map | null = null;
  private markerTransporteur: L.Marker | null = null;
  private markerOrigine: L.Marker | null = null;
  private markerDestination: L.Marker | null = null;
  private trajet: L.Polyline | null = null;
  private intervalId: ReturnType<typeof setInterval> | null = null;

  constructor(private route: ActivatedRoute, private api: ApiService) {}

  ngOnInit(): void {
    this.livraisonId = Number(this.route.snapshot.paramMap.get('id'));

    this.configurerIconesLeaflet();
    this.rafraichirPosition();
    this.intervalId = setInterval(() => this.rafraichirPosition(), INTERVALLE_RAFRAICHISSEMENT_MS);
  }

  ngOnDestroy(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
    }
  }

  private rafraichirPosition(): void {
    this.api.getPositionLivraison(this.livraisonId).subscribe({
      next: res => {
        this.erreur.set(null);
        this.statutLivraison.set(res.statut_livraison);
        this.derniereMiseAJour.set(res.position.timestamp);
        this.position.set({ latitude: res.position.latitude, longitude: res.position.longitude });
        this.mettreAJourCarte(
          { latitude: res.position.latitude, longitude: res.position.longitude },
          res.origine as PointCarte,
          res.destination as PointCarte
        );
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Position indisponible pour le moment.');
      },
    });
  }

  private mettreAJourCarte(
    transporteur: { latitude: number; longitude: number },
    origine: PointCarte,
    destination: PointCarte
  ): void {
    if (!this.map) {
      // Le conteneur #suivi-map ne devient présent dans le DOM qu'après
      // que position() passe à une valeur non nulle : on laisse Angular
      // rafraîchir le template avant d'initialiser la carte Leaflet.
      setTimeout(() => this.initialiserCarte(transporteur, origine, destination));
      return;
    }

    this.markerTransporteur?.setLatLng([transporteur.latitude, transporteur.longitude]);
    this.map.panTo([transporteur.latitude, transporteur.longitude]);
    this.mettreAJourTrajet(transporteur, origine, destination);
  }

  private initialiserCarte(
    transporteur: { latitude: number; longitude: number },
    origine: PointCarte,
    destination: PointCarte
  ): void {
    this.map = L.map('suivi-map').setView([transporteur.latitude, transporteur.longitude], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(this.map);

    this.markerTransporteur = L.marker([transporteur.latitude, transporteur.longitude])
      .addTo(this.map)
      .bindPopup('Transporteur');

    if (this.aDesCoordonnees(origine)) {
      this.markerOrigine = L.marker([origine.latitude!, origine.longitude!], { icon: this.icone('🏭') })
        .addTo(this.map)
        .bindPopup('Entrepôt (départ)');
    }

    if (this.aDesCoordonnees(destination)) {
      this.markerDestination = L.marker([destination.latitude!, destination.longitude!], { icon: this.icone('🏠') })
        .addTo(this.map)
        .bindPopup('Adresse de livraison (arrivée)');
    }

    this.mettreAJourTrajet(transporteur, origine, destination);

    // Cadre la carte pour englober tous les points disponibles.
    const points: L.LatLngExpression[] = [[transporteur.latitude, transporteur.longitude]];
    if (this.aDesCoordonnees(origine)) points.push([origine.latitude!, origine.longitude!]);
    if (this.aDesCoordonnees(destination)) points.push([destination.latitude!, destination.longitude!]);
    if (points.length > 1) {
      this.map.fitBounds(L.latLngBounds(points), { padding: [40, 40] });
    }
  }

  private mettreAJourTrajet(
    transporteur: { latitude: number; longitude: number },
    origine: PointCarte,
    destination: PointCarte
  ): void {
    if (!this.map) return;

    const points: L.LatLngExpression[] = [];
    if (this.aDesCoordonnees(origine)) points.push([origine.latitude!, origine.longitude!]);
    points.push([transporteur.latitude, transporteur.longitude]);
    if (this.aDesCoordonnees(destination)) points.push([destination.latitude!, destination.longitude!]);

    if (points.length < 2) return;

    if (!this.trajet) {
      this.trajet = L.polyline(points, { color: '#2d6a4f', weight: 3, dashArray: '6 6' }).addTo(this.map);
    } else {
      this.trajet.setLatLngs(points);
    }
  }

  private aDesCoordonnees(point: PointCarte): boolean {
    return point?.latitude != null && point?.longitude != null;
  }

  private icone(emoji: string): L.DivIcon {
    return L.divIcon({
      html: `<div style="font-size: 26px; line-height: 1; transform: translate(-50%, -100%);">${emoji}</div>`,
      className: '',
      iconSize: [0, 0],
    });
  }

  private configurerIconesLeaflet(): void {
    // Corrige le 404 classique des icônes par défaut de Leaflet une fois bundlées
    // (les chemins relatifs du CSS ne correspondent pas à la sortie du build Angular).
    delete (L.Icon.Default.prototype as any)._getIconUrl;
    L.Icon.Default.mergeOptions({
      iconRetinaUrl: 'leaflet/marker-icon-2x.png',
      iconUrl: 'leaflet/marker-icon.png',
      shadowUrl: 'leaflet/marker-shadow.png',
    });
  }
}
