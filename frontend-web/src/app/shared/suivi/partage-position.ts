import { Injectable, inject, signal } from '@angular/core';
import { ApiService } from '../../core/services/api.service';

export interface MissionPartagee { type: 'livraison' | 'reservation'; id: number; numero: string; }

const CLE_FILE = 'senagri_suivi_file';

/**
 * Partage de position depuis le navigateur du conducteur (lot 4), pendant
 * que la page est ouverte : sur téléphone, l'application garde le partage
 * écran verrouillé ; le site est un secours. Positions mises en file (avec
 * leur heure de prise) puis envoyées par paquets ; une coupure ne perd rien.
 */
@Injectable({ providedIn: 'root' })
export class PartagePositionService {
  private api = inject(ApiService);
  private surveillance: number | null = null;
  private relance: ReturnType<typeof setInterval> | null = null;
  private envoiEnCours = false;

  mission = signal<MissionPartagee | null>(null);
  erreur = signal<string | null>(null);
  enAttente = signal(0);

  get disponible(): boolean { return typeof navigator !== 'undefined' && !!navigator.geolocation; }

  partage(type: string, id: number): boolean { return this.mission()?.type === type && this.mission()?.id === id; }

  demarrer(m: MissionPartagee): void {
    if (!this.disponible) { this.erreur.set('Ce navigateur ne donne pas accès à la position.'); return; }
    this.arreter(false);
    this.erreur.set(null);
    this.mission.set(m);
    this.surveillance = navigator.geolocation.watchPosition(
      p => this.ajouter(p),
      e => this.erreur.set(e.code === e.PERMISSION_DENIED
        ? 'Position refusée par le navigateur : autorisez la localisation pour ce site.'
        : 'Position momentanément indisponible.'),
      { enableHighAccuracy: true, maximumAge: 5_000, timeout: 30_000 },
    );
    this.relance = setInterval(() => this.envoyer(), 30_000);
  }

  arreter(viderFile = true): void {
    if (this.surveillance !== null) navigator.geolocation.clearWatch(this.surveillance);
    this.surveillance = null;
    if (this.relance) clearInterval(this.relance);
    this.relance = null;
    this.mission.set(null);
    if (viderFile) this.envoyer();
  }

  private ajouter(p: GeolocationPosition): void {
    const m = this.mission();
    if (!m) return;
    this.erreur.set(null);
    const file = this.lire();
    file.push({
      type: m.type, id: m.id, latitude: p.coords.latitude, longitude: p.coords.longitude,
      horodatage: new Date(p.timestamp).toISOString(),
      ...(p.coords.accuracy ? { precision: Math.round(p.coords.accuracy) } : {}),
      ...(p.coords.speed ? { vitesse: Math.round(p.coords.speed * 36) / 10 } : {}),
    });
    this.ecrire(file.slice(-2000));
    this.envoyer();
  }

  envoyer(): void {
    const file = this.lire();
    if (this.envoiEnCours || file.length === 0) return;
    const premier = file[0];
    const paquet = file.filter(e => e.type === premier.type && e.id === premier.id).slice(0, 100);
    const cle = (e: any) => `${e.type}|${e.id}|${e.horodatage}`;
    const envoyees = new Set(paquet.map(cle));
    const retirer = () => this.ecrire(this.lire().filter(e => !envoyees.has(cle(e))));
    this.envoiEnCours = true;
    this.api.envoyerPositions(premier.type, premier.id, paquet.map(({ type, id, ...p }) => p)).subscribe({
      next: res => {
        this.envoiEnCours = false;
        retirer();
        if (res?.suivi_actif === false && this.partage(premier.type, premier.id)) this.arreter(false);
        if (this.lire().length) this.envoyer();
      },
      error: err => {
        this.envoiEnCours = false;
        // Réseau ou serveur indisponible : la file attend. Refus définitif : positions abandonnées.
        if (err.status && err.status < 500 && err.status !== 429) {
          retirer();
          if (this.partage(premier.type, premier.id)) this.arreter(false);
        }
      },
    });
  }

  private lire(): any[] {
    try { return JSON.parse(localStorage.getItem(CLE_FILE) ?? '[]'); } catch { return []; }
  }

  private ecrire(file: any[]): void {
    try { localStorage.setItem(CLE_FILE, JSON.stringify(file)); } catch { /* stockage indisponible */ }
    this.enAttente.set(file.length);
  }
}
