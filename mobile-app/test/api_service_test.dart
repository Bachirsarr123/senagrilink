import 'dart:convert';

import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// R4 — gestion de session côté mobile (aucun appel réseau réel : MockClient).
void main() {
  late List<http.Request> requetes;
  late List<FinSession> finsSession;

  void repondre(int statut, Map<String, dynamic> corps) {
    ApiService.client = MockClient((req) async {
      requetes.add(req);
      return http.Response(jsonEncode(corps), statut, headers: {'content-type': 'application/json'});
    });
  }

  setUp(() {
    requetes = [];
    finsSession = [];
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif', 'agri_user': '{"id":1}'});
    ApiService.onSessionInvalide = finsSession.add;
  });

  test('joint le jeton aux appels authentifiés', () async {
    repondre(200, {'catalogue': []});
    await ApiService.getCatalogue();
    expect(requetes.single.headers['Authorization'], 'Bearer jeton-fictif');
  });

  test('n\'envoie pas le jeton à la connexion ni à l\'inscription', () async {
    repondre(200, {'access_token': 'x', 'utilisateur': {}});
    await ApiService.login('a@test.sn', 'motdepasse-fictif');
    await ApiService.register({'email': 'b@test.sn'});
    expect(requetes.map((r) => r.headers.containsKey('Authorization')), everyElement(isFalse));
  });

  test('401 sur une requête authentifiée : session effacée et signalée', () async {
    repondre(401, {'message': 'Unauthenticated.'});

    await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>()));

    expect(await ApiService.getToken(), isNull);
    expect((await SharedPreferences.getInstance()).getString('agri_user'), isNull);
    expect(finsSession, [FinSession.expiree]);
  });

  test('403 compte bloqué : session effacée avec un motif distinct', () async {
    repondre(403, {'message': 'Votre compte a été bloqué.', 'code': 'compte_bloque'});

    await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>()));

    expect(await ApiService.getToken(), isNull);
    expect(finsSession, [FinSession.compteBloque]);
  });

  test('403 de permission ordinaire : session conservée', () async {
    repondre(403, {'message': 'Accès refusé. Rôle insuffisant.'});

    await expectLater(
      ApiService.getStocks(),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'statusCode', 403)),
    );

    expect(await ApiService.getToken(), 'jeton-fictif');
    expect(finsSession, isEmpty);
  });

  test('401 à la connexion (mauvais mot de passe) : pas de fin de session', () async {
    repondre(401, {'message': 'Identifiants incorrects.'});

    await expectLater(
      ApiService.login('a@test.sn', 'faux'),
      throwsA(isA<ApiException>().having((e) => e.message, 'message', 'Identifiants incorrects.')),
    );

    expect(await ApiService.getToken(), 'jeton-fictif');
    expect(finsSession, isEmpty);
  });

  group('persistance du jeton', () {
    const stockage = FlutterSecureStorage();

    test('jeton d\'une version précédente migré vers le stockage chiffré', () async {
      expect(await ApiService.getToken(), 'jeton-fictif');

      expect(await stockage.read(key: 'agri_token'), 'jeton-fictif');
      expect((await SharedPreferences.getInstance()).getString('agri_token'), isNull);
      expect(await ApiService.getToken(), 'jeton-fictif');
    });

    test('nouveau jeton enregistré uniquement dans le stockage chiffré', () async {
      await ApiService.saveToken('jeton-neuf');

      expect(await stockage.read(key: 'agri_token'), 'jeton-neuf');
      expect((await SharedPreferences.getInstance()).getString('agri_token'), isNull);
    });

    test('erreur réseau ou erreur serveur : session conservée', () async {
      ApiService.client = MockClient((req) async => throw http.ClientException('Connexion impossible'));
      await expectLater(ApiService.getCatalogue(), throwsA(isA<http.ClientException>()));

      for (final statut in [500, 502, 503]) {
        repondre(statut, {'message': 'Erreur serveur.'});
        await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>()));
      }

      expect(await ApiService.getToken(), 'jeton-fictif');
      expect((await SharedPreferences.getInstance()).getString('agri_user'), isNotNull);
      expect(finsSession, isEmpty);
    });

    test('401 tardif d\'un ancien jeton : la session ouverte depuis est conservée', () async {
      ApiService.client = MockClient((req) async {
        await ApiService.saveToken('nouveau-jeton'); // reconnexion pendant la requête
        return http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401);
      });

      await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>()));

      expect(await ApiService.getToken(), 'nouveau-jeton');
      expect(finsSession, isEmpty);
    });

    test('déconnexion : révocation demandée au serveur puis jeton effacé', () async {
      repondre(200, {'message': 'Déconnexion réussie.'});

      await ApiService.logout();

      expect(requetes.single.url.path, endsWith('/logout'));
      expect(requetes.single.headers['Authorization'], 'Bearer jeton-fictif');
      expect(await ApiService.getToken(), isNull);
      expect(await stockage.read(key: 'agri_token'), isNull);
      expect((await SharedPreferences.getInstance()).getString('agri_user'), isNull);
    });

    test('déconnexion hors connexion : session locale effacée quand même', () async {
      ApiService.client = MockClient((req) async => throw http.ClientException('Connexion impossible'));

      await ApiService.logout();

      expect(await ApiService.getToken(), isNull);
    });
  });
}
