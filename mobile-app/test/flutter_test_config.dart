import 'dart:async';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agri_platform_mobile/services/position_appareil.dart';

/// Exécuté avant chaque fichier de test : le stockage chiffré du jeton est
/// remplacé par une version en mémoire, vidée avant chaque test. Sans GPS ni
/// fond de carte (pas de réseau) : un test qui en a besoin simule la position.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    PositionAppareil.tuiles = false;
    PositionAppareil.lire = () async => null;
  });
  await testMain();
}
