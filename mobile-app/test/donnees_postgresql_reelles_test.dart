import 'dart:convert';
import 'dart:io';

import 'package:agri_platform_mobile/models/commande.dart';
import 'package:agri_platform_mobile/models/production.dart';
import 'package:agri_platform_mobile/models/stock.dart';
import 'package:flutter_test/flutter_test.dart';

/// P-07 — réponses réelles de l'API Laravel servie par PostgreSQL 16, capturées
/// sur une base locale de démonstration : `ancien_backend` (nombres en chaînes,
/// avant correction des modèles Laravel) et `backend_corrige` (nombres JSON).
/// L'application doit lire les deux sans erreur.
void main() {
  final fixtures = jsonDecode(File('test/fixtures/api_postgresql.json').readAsStringSync()) as Map<String, dynamic>;

  for (final version in ['ancien_backend', 'backend_corrige']) {
    group(version, () {
      final d = fixtures[version] as Map<String, dynamic>;

      test('stocks du gestionnaire', () {
        final stocks = (d['stocks']['stocks'] as List).map((j) => Stock.fromJson(j)).toList();
        expect(stocks.first.quantite, 70.5);
        expect(stocks.first.seuilAlerte, 100);
        expect(stocks.first.enAlerte, isTrue);
      });

      test('historique des commandes de l\'acheteur', () {
        final commandes = (d['historique']['commandes'] as List).map((j) => Commande.fromJson(j)).toList();
        // Commandes de 10 kg (sonde API) et 5 kg (parcours navigateur) passées sur la base de démonstration.
        expect(commandes.map((c) => c.quantite), unorderedEquals([10, 5]));
      });

      test('productions du producteur', () {
        final productions = (d['productions']['productions'] as List).map((j) => Production.fromJson(j)).toList();
        expect(productions.first.quantiteEstimee, 120.5);
        expect(productions.first.superficie, 1.5);
      });
    });
  }

  test('les deux captures diffèrent bien par le type (preuve de la capture)', () {
    expect(fixtures['ancien_backend']['stocks']['stocks'][0]['quantite'], isA<String>());
    expect(fixtures['backend_corrige']['stocks']['stocks'][0]['quantite'], isA<num>());
  });
}
