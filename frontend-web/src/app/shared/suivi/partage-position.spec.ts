import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { ApiService } from '../../core/services/api.service';
import { PartagePositionService } from './partage-position';

/** Lot 4 — partage depuis le navigateur : file hors connexion, paquets, arrêt à l'arrivée. */
describe('PartagePositionService', () => {
  let surPosition: (p: any) => void;
  let reponses: (() => any)[];
  let envois: any[];

  const position = (lat: number, t: number) => ({ coords: { latitude: lat, longitude: -17.4, accuracy: 9, speed: 10 }, timestamp: t });

  beforeEach(() => {
    localStorage.clear();
    envois = [];
    reponses = [];
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: {
      watchPosition: (ok: any) => { surPosition = ok; return 1; }, clearWatch: vi.fn(),
    } });
    TestBed.configureTestingModule({ providers: [{ provide: ApiService, useValue: {
      envoyerPositions: (type: string, id: number, positions: any[]) => { envois.push({ type, id, positions }); return (reponses.shift() ?? (() => of({ suivi_actif: true })))(); },
    } }] });
  });

  it('chaque position part avec son heure ; coupure : gardées puis renvoyées ensemble', () => {
    const s = TestBed.inject(PartagePositionService);
    s.demarrer({ type: 'livraison', id: 4, numero: 'LIV-4' });

    surPosition(position(14.78, Date.UTC(2026, 9, 21, 9, 30)));
    expect(envois[0]).toEqual({ type: 'livraison', id: 4, positions: [
      { latitude: 14.78, longitude: -17.4, horodatage: '2026-10-21T09:30:00.000Z', precision: 9, vitesse: 36 },
    ] });
    expect(s.enAttente()).toBe(0);

    reponses.push(() => throwError(() => ({ status: 0 })), () => throwError(() => ({ status: 0 })));
    surPosition(position(14.77, Date.UTC(2026, 9, 21, 9, 30, 10)));
    surPosition(position(14.76, Date.UTC(2026, 9, 21, 9, 30, 20)));
    expect(s.enAttente()).toBe(2);

    s.envoyer();
    expect(envois.at(-1).positions).toHaveLength(2);
    expect(s.enAttente()).toBe(0);
  });

  it('le serveur indique la fin du trajet : le partage s\'arrête', () => {
    const s = TestBed.inject(PartagePositionService);
    s.demarrer({ type: 'reservation', id: 7, numero: 'RES-7' });
    reponses.push(() => of({ suivi_actif: false }));
    surPosition(position(14.7, Date.now()));
    expect(s.mission()).toBeNull();
    expect(navigator.geolocation.clearWatch).toHaveBeenCalled();
  });

  it('position refusée par le navigateur : message clair', () => {
    let surErreur!: (e: any) => void;
    (navigator.geolocation as any).watchPosition = (_: any, ko: any) => { surErreur = ko; return 2; };
    const s = TestBed.inject(PartagePositionService);
    s.demarrer({ type: 'livraison', id: 4, numero: 'LIV-4' });
    surErreur({ code: 1, PERMISSION_DENIED: 1 });
    expect(s.erreur()).toContain('autorisez la localisation');
  });
});
