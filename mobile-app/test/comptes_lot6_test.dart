import 'dart:convert';

import 'package:agri_platform_mobile/screens/shared/notifications_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/services/notifications_push.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 6 — inscription en attente de validation, liste des notifications,
/// enregistrement du téléphone pour les notifications Firebase.
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  void api(Future<http.Response> Function(http.Request) f) {
    requetes = [];
    ApiService.client = MockClient((req) async { requetes.add(req); return f(req); });
  }

  test('inscription d\'un transporteur : en attente de validation, aucune session', () async {
    SharedPreferences.setMockInitialValues({});
    api((_) async => json({'message': 'Compte créé. Il sera activé après validation par l\'administrateur : vous serez prévenu.', 'en_attente_validation': true}, 201));
    final auth = AuthProvider();
    await auth.init();

    expect(await auth.register({'role': 'transporteur'}), isFalse);
    expect(auth.messageInscription, contains('validation'));
    expect(auth.erreur, isNull);
    expect(auth.isLoggedIn, isFalse);
    expect(await ApiService.getToken(), isNull);
  });

  test('téléphone enregistré pour les notifications, puis retiré à la déconnexion', () async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({'id': 1, 'nom': 'Sow', 'prenom': 'Fatou', 'email': 'f@test.sn', 'role': 'acheteur_gros', 'statut': 'actif'})});
    api((_) async => json({'message': 'ok'}));
    NotificationsPush.jetonTest = () async => 'jeton-fcm-1';
    addTearDown(() => NotificationsPush.jetonTest = null);
    final auth = AuthProvider();
    await auth.init();

    await NotificationsPush.activer();
    expect(requetes.single.url.path, endsWith('/appareils'));
    expect(jsonDecode(requetes.single.body), {'jeton': 'jeton-fcm-1', 'plateforme': 'android'});

    await auth.logout();
    final sortie = requetes.last;
    expect(sortie.url.path, endsWith('/logout'));
    expect(jsonDecode(sortie.body), {'jeton_appareil': 'jeton-fcm-1'});
  });

  test('sans configuration Firebase, rien n\'est demandé au téléphone', () async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton'});
    api((_) async => json({}));
    expect(NotificationsPush.configure, isFalse);
    await NotificationsPush.activer();
    expect(requetes, isEmpty);
  });

  testWidgets('liste des notifications : non lues en évidence, lecture et « Tout lire »', (tester) async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton'});
    api((req) async => req.method == 'GET'
        ? json({'notifications': [
            {'id': 'n1', 'data': {'type': 'compte_valide', 'message': 'Votre compte est activé.'}, 'read_at': null, 'created_at': '2026-10-21T08:00:00Z'},
            {'id': 'n2', 'data': {'type': 'incident', 'message': 'Incident déclaré sur LIV-2.'}, 'read_at': null, 'created_at': '2026-10-21T07:00:00Z'},
          ], 'non_lues': 2})
        : json({'message': 'ok'}));
    await tester.pumpWidget(ChangeNotifierProvider.value(value: AuthProvider(), child: const MaterialApp(home: NotificationsScreen())));
    await tester.pumpAndSettle();

    expect(find.text('Votre compte est activé.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('notification-n1')));
    await tester.pumpAndSettle();
    expect(requetes.last.url.path, endsWith('/notifications/n1/lue'));

    await tester.tap(find.byKey(const Key('tout-lire')));
    await tester.pumpAndSettle();
    expect(requetes.last.url.path, endsWith('/notifications/lues'));
    expect(find.byKey(const Key('tout-lire')), findsNothing);
  });
}
