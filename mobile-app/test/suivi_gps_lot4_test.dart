import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/suivi_gps_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 4 — partage de position : file gardée hors connexion, envoi par
/// paquets avec l'heure de prise, arrêt quand la mission n'est plus en route.
void main() {
  late StreamController<Position> gps;
  late List<http.Request> envois;
  late bool reseau;
  late bool suiviActif;
  final service = SuiviGpsService.instance;

  Position point(double lat, double lng, DateTime t, {double vitesse = 12.5}) => Position(
        latitude: lat, longitude: lng, timestamp: t, accuracy: 8, altitude: 0, altitudeAccuracy: 0,
        heading: 90, headingAccuracy: 0, speed: vitesse, speedAccuracy: 0);

  setUp(() async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton'});
    gps = StreamController<Position>.broadcast();
    envois = [];
    reseau = true;
    suiviActif = true;
    SuiviGpsService.sourcePositions = () => gps.stream;
    SuiviGpsService.autorisation = () async => true;
    ApiService.client = MockClient((req) async {
      if (!reseau) throw const SocketException('hors ligne');
      envois.add(req);
      if (req.url.path.endsWith('/suivi/actifs')) {
        return http.Response(jsonEncode({'suivis': [{'sujet_type': 'reservation', 'sujet_id': 7, 'numero': 'RES-7'}]}), 200,
            headers: {'content-type': 'application/json'});
      }
      final n = (jsonDecode(req.body)['positions'] as List).length;
      return http.Response(jsonEncode({'enregistrees': n, 'suivi_actif': suiviActif}), 201, headers: {'content-type': 'application/json'});
    });
  });

  tearDown(() async {
    await service.arreter();
    await gps.close();
  });

  test('chaque position est envoyée avec son heure, sa vitesse (km/h) et sa précision', () async {
    expect(await service.demarrer((type: 'livraison', id: 4, numero: 'LIV-4')), isTrue);
    final t = DateTime.utc(2026, 10, 21, 9, 30);
    gps.add(point(14.78, -16.97, t));
    await pumpEventQueue();

    expect(envois.single.url.path, endsWith('/suivi/livraison/4/positions'));
    expect(jsonDecode(envois.single.body), {'positions': [
      {'latitude': 14.78, 'longitude': -16.97, 'horodatage': '2026-10-21T09:30:00.000Z', 'precision': 8.0, 'vitesse': 45.0, 'cap': 90.0},
    ]});
    expect(await service.enAttente(), 0);
  });

  test('hors connexion : positions gardées puis renvoyées en un seul paquet', () async {
    await service.demarrer((type: 'livraison', id: 4, numero: 'LIV-4'));
    reseau = false;
    final t = DateTime.utc(2026, 10, 21, 9, 30);
    for (var i = 0; i < 3; i++) {
      gps.add(point(14.78 - i / 100, -16.97, t.add(Duration(seconds: 10 * i))));
      await pumpEventQueue();
    }
    expect(await service.enAttente(), 3);
    expect(envois, isEmpty);

    reseau = true;
    await service.envoyerFile();
    expect(envois, hasLength(1));
    expect((jsonDecode(envois.single.body)['positions'] as List).map((p) => p['horodatage']),
        ['2026-10-21T09:30:00.000Z', '2026-10-21T09:30:10.000Z', '2026-10-21T09:30:20.000Z']);
    expect(await service.enAttente(), 0);
  });

  test('mission arrivée côté serveur : le partage s\'arrête de lui-même', () async {
    await service.demarrer((type: 'livraison', id: 4, numero: 'LIV-4'));
    suiviActif = false;
    gps.add(point(14.7, -17.4, DateTime.utc(2026, 10, 21, 10)));
    await pumpEventQueue();
    expect(service.mission, isNull);
    expect(gps.hasListener, isFalse);
  });

  test('au démarrage de l\'application, le partage d\'une mission en route reprend', () async {
    await service.reprendre();
    expect(service.suit('reservation', 7), isTrue);
    expect(gps.hasListener, isTrue);
  });

  test('localisation refusée : rien ne démarre', () async {
    SuiviGpsService.autorisation = () async => false;
    expect(await service.demarrer((type: 'livraison', id: 4, numero: 'LIV-4')), isFalse);
    expect(service.mission, isNull);
  });
}
