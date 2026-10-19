import 'dart:convert';

import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Persistance de la session à travers les redémarrages de l'application
/// (chaque AuthProvider neuf + init() simule un lancement après fermeture).
void main() {
  const producteur = {'id': 1, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'awa@test.sn', 'role': 'producteur', 'statut': 'actif'};

  Future<AuthProvider> lancer() async {
    final auth = AuthProvider();
    await auth.init();
    return auth;
  }

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiService.client = MockClient((req) async {
      final corps = req.url.path.endsWith('/login')
          ? {'access_token': 'jeton-serveur', 'utilisateur': producteur}
          : {'message': 'Déconnexion réussie.'};
      return http.Response(jsonEncode(corps), 200, headers: {'content-type': 'application/json'});
    });
  });

  test('la session est restaurée après fermeture et redémarrage de l\'application', () async {
    expect(await (await lancer()).login('awa@test.sn', 'password123'), isTrue);

    final relance = await lancer();
    expect(relance.isLoggedIn, isTrue);
    expect(relance.role, 'producteur');
    expect(await ApiService.getToken(), 'jeton-serveur');
  });

  test('restauration sans réseau : aucun appel à l\'API au démarrage', () async {
    await (await lancer()).login('awa@test.sn', 'password123');
    ApiService.client = MockClient((req) async => throw http.ClientException('Hors ligne'));

    expect((await lancer()).isLoggedIn, isTrue);
  });

  test('pas de session restaurée sans jeton', () async {
    SharedPreferences.setMockInitialValues({'agri_user': jsonEncode(producteur)});

    expect((await lancer()).isLoggedIn, isFalse);
    expect((await SharedPreferences.getInstance()).getString('agri_user'), isNull);
  });

  test('après une déconnexion volontaire, la session n\'est pas restaurée', () async {
    final auth = await lancer();
    await auth.login('awa@test.sn', 'password123');

    await auth.logout();

    expect(auth.isLoggedIn, isFalse);
    expect(auth.messageSession, isNull);
    expect((await lancer()).isLoggedIn, isFalse);
  });
}
