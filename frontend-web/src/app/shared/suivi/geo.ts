/** Point géographique ; latitude/longitude nulles quand l'API ne les connaît pas. */
export interface Point {
  latitude: number | null | undefined;
  longitude: number | null | undefined;
}

/**
 * Le transporteur envoie sa position toutes les 10 s : au-delà d'une minute
 * sans nouvelle position, elle n'est plus présentée comme « en direct ».
 */
export const SEUIL_POSITION_DIRECTE_S = 60;

export function aDesCoordonnees(p: Point | null | undefined): p is { latitude: number; longitude: number } {
  return p?.latitude != null && p?.longitude != null
    && Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude));
}

/** Distance à vol d'oiseau (formule de haversine), en kilomètres. */
export function distanceKm(a: Point, b: Point): number | null {
  if (!aDesCoordonnees(a) || !aDesCoordonnees(b)) return null;
  const rad = (d: number) => (Number(d) * Math.PI) / 180;
  const dLat = rad(b.latitude - a.latitude);
  const dLon = rad(b.longitude - a.longitude);
  const h = Math.sin(dLat / 2) ** 2
    + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLon / 2) ** 2;
  return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** « 850 m », « 12,3 km », « 128 km ». */
export function formaterDistance(km: number | null): string | null {
  if (km == null) return null;
  if (km < 1) return `${Math.max(10, Math.round((km * 1000) / 10) * 10)} m`;
  return `${km.toLocaleString('fr-FR', { maximumFractionDigits: km < 100 ? 1 : 0 })} km`;
}

/** Date d'API (« 2026-10-19 11:30:24 » ou ISO 8601) en millisecondes, ou null. */
export function versMs(date: string | null | undefined): number | null {
  if (!date) return null;
  const ms = Date.parse(date.includes('T') ? date : date.replace(' ', 'T'));
  return Number.isNaN(ms) ? null : ms;
}

/** « à l'instant », « il y a 4 min », « il y a 2 h », « il y a 3 j ». */
export function libelleAge(secondes: number): string {
  if (secondes < 45) return 'à l’instant';
  const min = Math.round(secondes / 60);
  if (min < 60) return `il y a ${min} min`;
  const h = Math.round(min / 60);
  if (h < 48) return `il y a ${h} h`;
  return `il y a ${Math.round(h / 24)} j`;
}
