import 'dart:convert';

import 'package:agri_platform_mobile/screens/producteur/planifier_ventes_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Demandes d'acheteurs : contrat de GET /api/commandes/disponibles = { commandes_disponibles: [...] }.
void main() {
  setUp(() => SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif'}));

  void api(Map<String, Object> reponses) {
    ApiService.client = MockClient((req) async {
      final cle = reponses.keys.firstWhere((chemin) => req.url.path.endsWith(chemin));
      return http.Response(jsonEncode(reponses[cle]), 200, headers: {'content-type': 'application/json'});
    });
  }

  testWidgets('les demandes renvoyées par l\'API sont affichées', (tester) async {
    api({
      '/productions': {'productions': []},
      '/commandes/disponibles': {
        'commandes_disponibles': [
          {'id': 7, 'numero_commande': 'CMD-TEST', 'produit': 'riz', 'quantite': 10, 'prix': null, 'statut': 'en_attente'},
        ],
      },
    });

    await tester.pumpWidget(const MaterialApp(home: PlanifierVentesScreen()));
    await tester.pumpAndSettle();

    expect(find.text('Demandes (1)'), findsOneWidget);
    await tester.tap(find.text('Demandes (1)'));
    await tester.pumpAndSettle();
    expect(find.text('Commande : CMD-TEST'), findsOneWidget);
  });

  testWidgets('une réponse inattendue est signalée au lieu d\'une liste vide', (tester) async {
    api({
      '/productions': {'productions': []},
      '/commandes/disponibles': {'autre_cle': []},
    });

    await tester.pumpWidget(const MaterialApp(home: PlanifierVentesScreen()));
    await tester.pumpAndSettle();

    expect(find.text('Réponse inattendue du serveur.'), findsOneWidget);
  });
}
