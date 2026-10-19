import { aDesCoordonnees, distanceKm, formaterDistance, libelleAge, versMs } from './geo';

describe('geo — calculs du suivi', () => {
  it('calcule la distance à vol d\'oiseau Dakar → Thiès (≈ 59 km)', () => {
    const km = distanceKm({ latitude: 14.6928, longitude: -17.4467 }, { latitude: 14.7910, longitude: -16.9359 })!;
    expect(km).toBeGreaterThan(55);
    expect(km).toBeLessThan(60);
  });

  it('ne calcule rien sans coordonnées complètes', () => {
    expect(distanceKm({ latitude: 14.7, longitude: null }, { latitude: 14.8, longitude: -17 })).toBeNull();
    expect(aDesCoordonnees({ latitude: null, longitude: null })).toBe(false);
    expect(aDesCoordonnees({ latitude: 0, longitude: 0 })).toBe(true);
  });

  it('formate les distances en m ou km', () => {
    expect(formaterDistance(0.42)).toBe('420 m');
    expect(formaterDistance(12.34)).toMatch(/^12,3\s?km$/);
    expect(formaterDistance(null)).toBeNull();
  });

  it('exprime l\'âge d\'une position', () => {
    expect(libelleAge(10)).toBe('à l’instant');
    expect(libelleAge(4 * 60)).toBe('il y a 4 min');
    expect(libelleAge(3 * 3600)).toBe('il y a 3 h');
  });

  it('lit les dates SQL et ISO, refuse les dates invalides', () => {
    expect(versMs('2026-10-19 11:30:24')).toBe(Date.parse('2026-10-19T11:30:24'));
    expect(versMs('2026-10-19T11:30:24.000000Z')).toBe(Date.parse('2026-10-19T11:30:24Z'));
    expect(versMs('pas une date')).toBeNull();
    expect(versMs(null)).toBeNull();
  });
});
