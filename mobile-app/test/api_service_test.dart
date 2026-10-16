import 'dart:convert';

import 'package:agri_platform_mobile/services/api_service.dart';
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
}
