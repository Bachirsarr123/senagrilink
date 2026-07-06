import { Component, OnDestroy, OnInit, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { DatePipe } from '@angular/common';
import * as L from 'leaflet';
import { ApiService } from '../../../core/services/api.service';

const INTERVALLE_RAFRAICHISSEMENT_MS = 10000;

@Component({
  selector: 'app-suivi-livraison',
  standalone: true,
  imports: [DatePipe],
  template: `
    <div class="page">
      <div class="page-header">
        <h1>Suivi de livraison en direct</h1>
        <span class="subtitle">Position du transporteur actualisée toutes les 10 secondes</span>
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
        }
      </div>
    </div>
  `,
  styleUrls: ['../../shared.styles.scss'],
})
export class SuiviLivraisonComponent implements OnInit, OnDestroy {
  position          = signal<{ latitude: number; longitude: number } | null>(null);
  statutLivraison   = signal<string | null>(null);
  derniereMiseAJour = signal<string | null>(null);
  erreur            = signal<string | null>(null);

  private livraisonId!: number;
  private map: L.Map | null = null;
  private marker: L.Marker | null = null;
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
        this.mettreAJourCarte(res.position.latitude, res.position.longitude);
      },
      error: err => {
        this.erreur.set(err.error?.message ?? 'Position indisponible pour le moment.');
      },
    });
  }

  private mettreAJourCarte(lat: number, lng: number): void {
    if (!this.map) {
      // Le conteneur #suivi-map ne devient présent dans le DOM qu'après
      // que position() passe à une valeur non nulle : on laisse Angular
      // rafraîchir le template avant d'initialiser la carte Leaflet.
      setTimeout(() => {
        this.map = L.map('suivi-map').setView([lat, lng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '© OpenStreetMap contributors',
          maxZoom: 19,
        }).addTo(this.map);
        this.marker = L.marker([lat, lng]).addTo(this.map).bindPopup('Transporteur');
      });
      return;
    }

    this.marker?.setLatLng([lat, lng]);
    this.map.panTo([lat, lng]);
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
