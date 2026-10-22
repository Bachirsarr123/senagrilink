import 'package:flutter/widgets.dart';
import 'package:geolocator/geolocator.dart';
import 'package:provider/provider.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/utilisateur.dart';
import '../utils/nombres.dart';
import 'api_service.dart';
import 'auth_provider.dart';

/// Position du téléphone, prise automatiquement (lot 8).
///
/// L'autorisation n'est demandée qu'une seule fois : accordée, Android la retient
/// et la position est lue sans rien demander ; refusée, on ne la redemande plus
/// (la personne place le point sur la carte si elle le souhaite).
class PositionAppareil {
  static const _cleDemandee = 'position_autorisation_demandee';

  /// Fonds de carte (désactivés dans les tests, sans réseau).
  static bool tuiles = true;

  /// Lecture de la position, remplaçable dans les tests.
  static Future<LatLng?> Function() lire = _lireReelle;

  static Future<LatLng?> _lireReelle() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        final prefs = await SharedPreferences.getInstance();
        if (prefs.getBool(_cleDemandee) ?? false) return null; // déjà demandée une fois : on ne redemande pas
        await prefs.setBool(_cleDemandee, true);
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return null;
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 12)),
      ).catchError((_) async => (await Geolocator.getLastKnownPosition())!);
      return LatLng(_arrondir(p.latitude), _arrondir(p.longitude));
    } catch (_) {
      return null;
    }
  }

  /// Lieu habituel enregistré au profil (exploitation, entrepôt, adresse de livraison).
  static LatLng? lieuHabituel(Utilisateur? u) {
    final p = u?.producteur ?? u?.entrepot ?? u?.acheteurGros;
    final lat = nombre(p?['latitude']), lng = nombre(p?['longitude']);
    return lat != null && lng != null ? LatLng(lat, lng) : null;
  }

  /// Lieu habituel de la personne connectée (aucun hors session).
  static LatLng? lieuHabituelConnecte(BuildContext context) {
    try {
      return lieuHabituel(context.read<AuthProvider>().utilisateur);
    } on ProviderNotFoundException {
      return null;
    }
  }

  static Future<Map<String, dynamic>>? _fond;

  /// Fond de carte réglé sur le serveur (MapTiler en préproduction), partagé par toutes les cartes.
  static Future<Map<String, dynamic>> fondDeCarte() => _fond ??= ApiService.getConfigurationCarte()
      .then((r) => (r as Map).cast<String, dynamic>())
      .catchError((_) { _fond = null; return <String, dynamic>{}; });
}

/// Arrondi à 7 décimales (précision de la colonne en base, ~1 cm).
double _arrondir(double v) => (v * 1e7).roundToDouble() / 1e7;
