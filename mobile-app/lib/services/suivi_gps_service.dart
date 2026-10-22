import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';

/// Mission dont la position est partagée (livraison ou acheminement vers l'entrepôt).
typedef MissionSuivie = ({String type, int id, String numero});

/// Partage de position pendant le trajet (lot 4).
///
/// - **Arrière-plan** : sur Android, le flux de positions passe par un service
///   de premier plan (notification « Suivi de livraison actif ») ; il continue
///   téléphone verrouillé ou application quittée vers un autre écran. Le
///   service ne dépend d'aucun écran : il est arrêté par l'arrivée, ou quand le
///   serveur répond que la mission n'est plus en route.
/// - **Hors connexion** : chaque position est d'abord rangée dans une file
///   gardée sur le téléphone (avec son heure de prise), puis envoyée par
///   paquets dès que le réseau revient. Le serveur ignore les doublons.
class SuiviGpsService {
  SuiviGpsService._();
  static final instance = SuiviGpsService._();

  static const _cleFile = 'suivi_file';
  static const _cleMission = 'suivi_mission';
  static const tailleMaxFile = 2000;
  static const taillePaquet = 100;

  /// Remplaçable dans les tests (aucun GPS réel).
  static Stream<Position> Function()? sourcePositions;
  static Future<bool> Function()? autorisation;

  final etat = ValueNotifier<MissionSuivie?>(null);
  StreamSubscription<Position>? _abonnement;
  Timer? _relance;
  bool _envoiEnCours = false;

  MissionSuivie? get mission => etat.value;
  bool suit(String type, int id) => mission?.type == type && mission?.id == id;

  /// Démarre le partage pour cette mission. Faux si la localisation est refusée ou désactivée.
  Future<bool> demarrer(MissionSuivie m) async {
    if (suit(m.type, m.id) && _abonnement != null) return true;
    if (!await (autorisation ?? _verifierPermissions)()) return false;

    await _arreterFlux();
    etat.value = m;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_cleMission, jsonEncode({'type': m.type, 'id': m.id, 'numero': m.numero}));

    _abonnement = (sourcePositions ?? _fluxAppareil)().listen(_surPosition, onError: (_) {});
    _relance = Timer.periodic(const Duration(seconds: 30), (_) => envoyerFile());
    return true;
  }

  /// Arrête le partage (arrivée). Les positions encore en file sont envoyées une dernière fois.
  Future<void> arreter() async {
    await _arreterFlux();
    etat.value = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_cleMission);
    await envoyerFile();
  }

  /// Au démarrage de l'application : reprend le partage d'une mission en route
  /// (téléphone redémarré, application fermée par le système) et vide la file.
  Future<void> reprendre() async {
    await envoyerFile();
    try {
      final res = await ApiService.getSuivisActifs();
      final suivis = ((res['suivis'] as List?) ?? const []).cast<Map<String, dynamic>>();
      if (suivis.isEmpty) {
        if (mission != null) await arreter();
        return;
      }
      final s = suivis.first;
      await demarrer((type: s['sujet_type'] as String, id: s['sujet_id'] as int, numero: s['numero'] as String? ?? ''));
    } on ApiException {
      // Hors connexion : on réessaiera au prochain démarrage ou à la prochaine étape.
    }
  }

  Future<void> _arreterFlux() async {
    await _abonnement?.cancel();
    _abonnement = null;
    _relance?.cancel();
    _relance = null;
  }

  Future<void> _surPosition(Position p) async {
    final m = mission;
    if (m == null) return;
    final prefs = await SharedPreferences.getInstance();
    final file = _lireFile(prefs)
      ..add({
        'type': m.type, 'id': m.id,
        'latitude': p.latitude, 'longitude': p.longitude,
        'horodatage': p.timestamp.toUtc().toIso8601String(),
        if (p.accuracy > 0) 'precision': double.parse(p.accuracy.toStringAsFixed(1)),
        if (p.speed > 0) 'vitesse': double.parse((p.speed * 3.6).toStringAsFixed(1)),
        if (p.heading >= 0 && p.heading <= 360) 'cap': double.parse(p.heading.toStringAsFixed(0)),
      });
    // File bornée : en cas de très longue coupure, les plus anciennes partent d'abord.
    await prefs.setString(_cleFile, jsonEncode(file.length > tailleMaxFile ? file.sublist(file.length - tailleMaxFile) : file));
    await envoyerFile();
  }

  /// Envoie la file par paquets, mission par mission. Ce qui échoue reste en file.
  Future<void> envoyerFile() async {
    if (_envoiEnCours) return;
    _envoiEnCours = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      var file = _lireFile(prefs);
      while (file.isNotEmpty) {
        final premier = file.first;
        final paquet = file.where((e) => e['type'] == premier['type'] && e['id'] == premier['id']).take(taillePaquet).toList();
        Map<String, dynamic>? res;
        try {
          res = await ApiService.envoyerPositions(premier['type'] as String, premier['id'] as int, [
            for (final e in paquet) {for (final k in ['latitude', 'longitude', 'horodatage', 'precision', 'vitesse', 'cap']) if (e[k] != null) k: e[k]},
          ]) as Map<String, dynamic>?;
        } on ApiException catch (e) {
          // Réseau absent ou serveur indisponible : on garde la file pour plus tard.
          if (e.statusCode == null || e.statusCode! >= 500 || e.statusCode == 429) break;
          res = {'suivi_actif': false}; // refus définitif (mission retirée, pas encore partie) : positions abandonnées
        }
        String cle(Map e) => '${e['type']}|${e['id']}|${e['horodatage']}';
        final envoyees = paquet.map(cle).toSet();
        file = _lireFile(prefs)..removeWhere((e) => envoyees.contains(cle(e)));
        await prefs.setString(_cleFile, jsonEncode(file));
        if (res?['suivi_actif'] == false && suit(premier['type'] as String, premier['id'] as int)) {
          await _arreterFlux();
          etat.value = null;
          await prefs.remove(_cleMission);
        }
      }
    } finally {
      _envoiEnCours = false;
    }
  }

  /// Nombre de positions en attente d'envoi (affiché au conducteur).
  Future<int> enAttente() async => _lireFile(await SharedPreferences.getInstance()).length;

  List<Map<String, dynamic>> _lireFile(SharedPreferences prefs) {
    try {
      return ((jsonDecode(prefs.getString(_cleFile) ?? '[]') as List).cast<Map>()).map((e) => e.cast<String, dynamic>()).toList();
    } catch (_) {
      return [];
    }
  }

  Stream<Position> _fluxAppareil() {
    final LocationSettings reglages = defaultTargetPlatform == TargetPlatform.android
        ? AndroidSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: 15,
            intervalDuration: const Duration(seconds: 10),
            foregroundNotificationConfig: const ForegroundNotificationConfig(
              notificationTitle: 'Suivi de livraison actif',
              notificationText: 'Votre position est partagée avec les personnes concernées jusqu\'à l\'arrivée.',
              notificationChannelName: 'Suivi de livraison',
              enableWakeLock: true,
              setOngoing: true,
            ),
          )
        : const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 15);
    return Geolocator.getPositionStream(locationSettings: reglages);
  }

  Future<bool> _verifierPermissions() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return false;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      return permission == LocationPermission.always || permission == LocationPermission.whileInUse;
    } catch (_) {
      return false; // localisation indisponible sur cet appareil
    }
  }
}
