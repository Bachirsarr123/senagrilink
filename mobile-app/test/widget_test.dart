import 'dart:convert';

import 'package:agri_platform_mobile/main.dart';
import 'package:agri_platform_mobile/screens/auth/login_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/productions_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Point d'entrée réel de l'application (AgriPlatformApp + AuthProvider, comme
/// dans main()). Aucun appel réseau : l'API est simulée par un MockClient.
void main() {
  const producteur = {'id': 1, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'awa@test.sn', 'role': 'producteur', 'statut': 'actif'};

  Future<AuthProvider> demarrer(WidgetTester tester) async {
    final auth = AuthProvider();
    await auth.init();
    brancherFinSession(auth);
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: const AgriPlatformApp()));
    await tester.pump();
    return auth;
  }

  void api(int statutProductions) {
    ApiService.client = MockClient((req) async {
      final corps = req.url.path.endsWith('/notifications')
          ? {'notifications': [], 'non_lues': 0}
          : statutProductions == 200 ? {'productions': []} : {'message': 'Unauthenticated.'};
      final statut = req.url.path.endsWith('/notifications') ? 200 : statutProductions;
      return http.Response(jsonEncode(corps), statut, headers: {'content-type': 'application/json'});
    });
  }

  /// Retire l'application pour arrêter le minuteur de notifications de HomeRouter.
  Future<void> arreter(WidgetTester tester) => tester.pumpWidget(const SizedBox());

  testWidgets('sans session, l\'application ouvre l\'écran de connexion', (tester) async {
    SharedPreferences.setMockInitialValues({});
    api(200);

    await demarrer(tester);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.text('Connexion'), findsOneWidget);
  });

  testWidgets('avec une session enregistrée, le producteur arrive sur ses productions', (tester) async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif', 'agri_user': jsonEncode(producteur)});
    api(200);

    await demarrer(tester);
    await tester.pump();

    expect(find.byType(ProductionsScreen), findsOneWidget);
    expect(find.text('Bonjour, Awa'), findsOneWidget);
    await arreter(tester);
  });

  testWidgets('un 401 de l\'API ramène à la connexion avec le motif', (tester) async {
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif', 'agri_user': jsonEncode(producteur)});
    api(401);

    final auth = await demarrer(tester);
    await tester.pumpAndSettle();

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.text('Votre session a expiré. Veuillez vous reconnecter.'), findsOneWidget);
    expect(auth.isLoggedIn, isFalse);
    expect(await ApiService.getToken(), isNull);
    await arreter(tester);
  });
}
