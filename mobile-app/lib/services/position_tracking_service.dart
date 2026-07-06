import 'dart:async';
import 'package:geolocator/geolocator.dart';
import 'api_service.dart';

/// Envoie la position GPS du transporteur toutes les 10 secondes
/// pendant qu'une livraison est en cours (en_cours).
class PositionTrackingService {
  Timer? _timer;

  bool get enCours => _timer != null;

  /// Démarre l'envoi périodique. Retourne false si la permission de
  /// localisation est refusée ou le service GPS désactivé.
  Future<bool> demarrer() async {
    if (enCours) return true;

    final autorise = await _verifierPermissions();
    if (!autorise) return false;

    _envoyerPositionActuelle();
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _envoyerPositionActuelle());
    return true;
  }

  void arreter() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _envoyerPositionActuelle() async {
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high),
      );
      await ApiService.envoyerPosition(position.latitude, position.longitude);
    } catch (_) {
      // Échec ponctuel (GPS indisponible, réseau...) : nouvelle tentative au prochain tick.
    }
  }

  Future<bool> _verifierPermissions() async {
    if (!await Geolocator.isLocationServiceEnabled()) return false;

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    return permission == LocationPermission.always || permission == LocationPermission.whileInUse;
  }
}
