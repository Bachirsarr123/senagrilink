import 'dart:math' as math;

/// Le transporteur envoie sa position toutes les 10 s : au-delà d'une minute
/// sans nouvelle position, elle n'est plus présentée comme « en direct ».
const seuilPositionDirecte = Duration(seconds: 60);

/// Coordonnées d'un point de l'API (`{latitude, longitude}`), ou null si incomplètes.
({double lat, double lng})? coordonnees(Object? point) {
  if (point is! Map) return null;
  final lat = point['latitude'], lng = point['longitude'];
  if (lat is! num || lng is! num) return null;
  return (lat: lat.toDouble(), lng: lng.toDouble());
}

/// Distance à vol d'oiseau (haversine), en kilomètres.
double distanceKm(({double lat, double lng}) a, ({double lat, double lng}) b) {
  double rad(double d) => d * math.pi / 180;
  final dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
  final h = math.pow(math.sin(dLat / 2), 2) +
      math.cos(rad(a.lat)) * math.cos(rad(b.lat)) * math.pow(math.sin(dLng / 2), 2);
  return 2 * 6371 * math.asin(math.min(1, math.sqrt(h)));
}

/// « 420 m », « 12,3 km », « 128 km ».
String formaterDistance(double km) {
  if (km < 1) return '${math.max(10, (km * 100).round() * 10)} m';
  final texte = km < 100 ? km.toStringAsFixed(1) : km.toStringAsFixed(0);
  return '${texte.replaceAll('.', ',')} km';
}

/// « à l'instant », « il y a 4 min », « il y a 2 h », « il y a 3 j ».
String libelleAge(Duration age) {
  if (age.inSeconds < 45) return 'à l’instant';
  final min = (age.inSeconds / 60).round();
  if (min < 60) return 'il y a $min min';
  final h = (min / 60).round();
  if (h < 48) return 'il y a $h h';
  return 'il y a ${(h / 24).round()} j';
}

/// Date d'API (« 2026-10-19 11:30:24 » ou ISO 8601), ou null.
DateTime? lireDate(Object? valeur) => valeur is String ? DateTime.tryParse(valeur) : null;
