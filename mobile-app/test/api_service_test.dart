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
      // Erreur réseau convertie en ApiException (affichable par tous les écrans), sans code HTTP.
      await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>().having((e) => e.statusCode, 'statusCode', isNull)));

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

  group('robustesse réseau (P-20)', () {
    test('délai dépassé : ApiException explicite, session conservée', () async {
      ApiService.delaiMaximal = const Duration(milliseconds: 50);
      addTearDown(() => ApiService.delaiMaximal = const Duration(seconds: 60));
      ApiService.client = MockClient((req) => Future.delayed(const Duration(seconds: 2), () => http.Response('{}', 200)));

      await expectLater(ApiService.getCatalogue(),
          throwsA(isA<ApiException>().having((e) => e.message, 'message', contains('ne répond pas'))));
      expect(await ApiService.getToken(), 'jeton-fictif');
    });

    test('page HTML 502 d\'un proxy : ApiException avec le code, session conservée', () async {
      ApiService.client = MockClient((req) async => http.Response('<html>Bad Gateway</html>', 502));

      await expectLater(ApiService.getCatalogue(),
          throwsA(isA<ApiException>().having((e) => e.statusCode, 'statusCode', 502)));
      expect(await ApiService.getToken(), 'jeton-fictif');
      expect(finsSession, isEmpty);
    });

    test('réponse 200 non JSON : ApiException, jamais d\'exception brute', () async {
      ApiService.client = MockClient((req) async => http.Response('<html>maintenance</html>', 200));
      await expectLater(ApiService.getCatalogue(), throwsA(isA<ApiException>()));
    });
  });

  group('idempotence (P-25)', () {
    test('la clé de soumission est envoyée et reste identique lors d\'un nouvel essai', () async {
      var essai = 0;
      ApiService.client = MockClient((req) async {
        requetes.add(req);
        essai++;
        return essai == 1
            ? http.Response('<html>Bad Gateway</html>', 502)
            : http.Response(jsonEncode({'commande': {'id': 1, 'numero_commande': 'CMD-1'}}), 201);
      });
      final cle = ApiService.nouvelleCleIdempotence();

      await expectLater(ApiService.passerCommande({'stock_id': 1, 'quantite': 2}, cleIdempotence: cle), throwsA(isA<ApiException>()));
      await ApiService.passerCommande({'stock_id': 1, 'quantite': 2}, cleIdempotence: cle);

      expect(requetes.map((r) => r.headers['Idempotency-Key']), [cle, cle]);
    });

    test('format de clé accepté par le serveur ([A-Za-z0-9_-]{8,100}) et clés distinctes', () {
      final a = ApiService.nouvelleCleIdempotence(), b = ApiService.nouvelleCleIdempotence();
      expect(RegExp(r'^[A-Za-z0-9_-]{8,100}$').hasMatch(a), isTrue);
      expect(a, isNot(b));
    });

    test('sans clé, aucun en-tête (compatibilité)', () async {
      repondre(200, {'catalogue': []});
      await ApiService.getCatalogue();
      expect(requetes.single.headers.containsKey('Idempotency-Key'), isFalse);
    });
  });
}
