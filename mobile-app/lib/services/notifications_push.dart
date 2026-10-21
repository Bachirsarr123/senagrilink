import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';

/// Notifications sur téléphone par Firebase (lot 6, Q15).
///
/// La configuration Firebase est fournie à la construction de l'APK :
/// `--dart-define=FIREBASE_API_KEY=… FIREBASE_APP_ID=… FIREBASE_SENDER_ID=…
/// FIREBASE_PROJECT_ID=…` (valeurs du projet Firebase, non secrètes). Sans
/// elle, rien n'est activé : les notifications restent visibles dans
/// l'application. Le serveur envoie le même texte que la liste des notifications.
class NotificationsPush {
  NotificationsPush._();

  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const _cleJeton = 'push_jeton';

  static bool get configure => _apiKey.isNotEmpty && _appId.isNotEmpty && _senderId.isNotEmpty && _projectId.isNotEmpty;

  /// Remplaçable dans les tests : jeton de l'appareil sans Firebase.
  @visibleForTesting
  static Future<String?> Function()? jetonTest;

  static bool _initialise = false;

  /// Après la connexion : demande l'autorisation (Android 13+) et enregistre
  /// le téléphone auprès du serveur. Silencieux en cas d'échec.
  static Future<void> activer() async {
    try {
      String? jeton;
      if (jetonTest != null) {
        jeton = await jetonTest!();
      } else {
        if (!configure || defaultTargetPlatform != TargetPlatform.android) return;
        if (!_initialise) {
          await Firebase.initializeApp(options: const FirebaseOptions(
            apiKey: _apiKey, appId: _appId, messagingSenderId: _senderId, projectId: _projectId,
          ));
          _initialise = true;
          FirebaseMessaging.instance.onTokenRefresh.listen(_enregistrer);
        }
        await FirebaseMessaging.instance.requestPermission();
        jeton = await FirebaseMessaging.instance.getToken();
      }
      if (jeton != null) await _enregistrer(jeton);
    } catch (_) {
      // Pas de service Google sur l'appareil, réseau absent… : l'appli fonctionne sans.
    }
  }

  static Future<void> _enregistrer(String jeton) async {
    await ApiService.enregistrerAppareil(jeton);
    (await SharedPreferences.getInstance()).setString(_cleJeton, jeton);
  }

  /// Jeton enregistré, envoyé à la déconnexion pour que ce téléphone ne reçoive plus rien.
  static Future<String?> jetonEnregistre() async => (await SharedPreferences.getInstance()).getString(_cleJeton);
}
