import { Injectable, inject } from '@angular/core';
import { Observable, catchError, of, shareReplay } from 'rxjs';
import { ApiService } from '../../core/services/api.service';
import { AuthService } from '../../core/auth/auth.service';
import type { Position } from './choix-position';

export interface FondDeCarte { tuiles_url?: string; attribution?: string; }

/**
 * Position de l'appareil, prise automatiquement (lot 8).
 *
 * Le navigateur ne demande l'autorisation qu'une fois et retient la réponse :
 * une fois accordée, la position est lue sans rien demander ; refusée, on ne
 * redemande jamais (la personne place le point sur la carte si elle le veut).
 */
@Injectable({ providedIn: 'root' })
export class PositionAppareilService {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private fond$?: Observable<FondDeCarte>;

  /** Position actuelle, ou null (refusée, indisponible, délai dépassé). */
  async actuelle(delaiMs = 12000): Promise<Position | null> {
    if (typeof navigator === 'undefined' || typeof navigator.geolocation?.getCurrentPosition !== 'function') return null;
    try {
      const etat = await navigator.permissions?.query({ name: 'geolocation' as PermissionName });
      if (etat?.state === 'denied') return null;
    } catch { /* API des permissions absente : le navigateur demandera lui-même, une seule fois */ }
    return new Promise(resolve => navigator.geolocation.getCurrentPosition(
      p => resolve({ latitude: Math.round(p.coords.latitude * 1e7) / 1e7, longitude: Math.round(p.coords.longitude * 1e7) / 1e7 }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: delaiMs, maximumAge: 60000 },
    ));
  }

  /** Lieu habituel enregistré au profil (exploitation, entrepôt, adresse de livraison). */
  lieuHabituel(): Position | null {
    const u: any = this.auth.utilisateur();
    const p = u?.producteur ?? u?.entrepot ?? u?.acheteur_gros;
    return p?.latitude != null && p?.longitude != null ? { latitude: +p.latitude, longitude: +p.longitude } : null;
  }

  /** Fond de carte réglé sur le serveur (MapTiler en préproduction), partagé par toutes les cartes. */
  fondDeCarte(): Observable<FondDeCarte> {
    return this.fond$ ??= this.api.getConfigurationCarte().pipe(catchError(() => of({})), shareReplay(1));
  }
}
