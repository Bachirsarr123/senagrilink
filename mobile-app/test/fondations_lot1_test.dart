import 'dart:convert';

import 'package:agri_platform_mobile/main.dart';
import 'package:agri_platform_mobile/models/commande.dart';
import 'package:agri_platform_mobile/models/production.dart';
import 'package:agri_platform_mobile/models/stock.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/utils/nombres.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 1 : prix du producteur, stock réservé / disponible, lot verrouillé.
/// Nombres au format PostgreSQL (chaînes), comme renvoyés par l'API réelle.
void main() {
  group('modèles', () {
    test('stock : disponible fourni par l\'API, alerte sur le disponible', () {
      final s = Stock.fromJson({
        'id': 1, 'produit': 'riz', 'quantite': '60', 'quantite_reservee': '10',
        'quantite_disponible': '50', 'seuil_alerte': '55', 'statut': 'disponible',
      });
      expect([s.quantite, s.quantiteReservee, s.quantiteDisponible], [60, 10, 50]);
      expect(s.enAlerte, isTrue); // 50 <= 55 alors que le physique (60) dépasse
    });

    test('stock : ancien serveur sans réservé, disponible = physique', () {
      final s = Stock.fromJson({'id': 1, 'produit': 'riz', 'quantite': 40, 'statut': 'disponible'});
      expect(s.quantiteDisponible, 40);
    });

    test('récolte : prix et verrouillage une fois en entrepôt', () {
      final enAttente = Production.fromJson({'id': 1, 'code_tracabilite': 'LOT-1', 'statut': 'en_attente', 'prix_unitaire': '150'});
      final recue = Production.fromJson({'id': 2, 'code_tracabilite': 'LOT-2', 'statut': 'en_attente', 'stocks': [{'id': 3}]});
      expect(enAttente.prixUnitaire, 150);
      expect(enAttente.estVerrouille, isFalse);
      expect(recue.estVerrouille, isTrue);
    });

    test('commande : prix au kilo figé et montant', () {
      final c = Commande.fromJson({'id': 1, 'produit': 'riz', 'quantite': '4', 'prix': '600.00', 'prix_unitaire': '150.00', 'statut': 'en_attente'});
      expect([c.prixUnitaire, c.prix], [150, 600]);
    });
  });

  group('affichage et saisie des nombres', () {
    test('sans arrondi trompeur', () {
      expect(kg(12), '12 kg');
      expect(kg(12.5), '12,5 kg');
      expect(kg(0.25), '0,25 kg');
      expect(fcfa(1875), '1875 FCFA');
      expect(nombreLisible(100), '100');
    });

    test('prix au kilo obligatoire, au moins 1 FCFA, virgule acceptée', () {
      expect(validerPrixAuKilo(''), isNotNull);
      expect(validerPrixAuKilo('0,5'), isNotNull);
      expect(validerPrixAuKilo('abc'), isNotNull);
      expect(validerPrixAuKilo('250,5'), isNull);
    });
  });

  group('écrans', () {
    late List<http.Request> requetes;

    Future<void> demarrer(WidgetTester tester, Map<String, dynamic> utilisateur, Future<http.Response> Function(http.Request) api) async {
      requetes = [];
      SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif', 'agri_user': jsonEncode(utilisateur)});
      ApiService.client = MockClient((req) async {
        requetes.add(req);
        if (req.url.path.endsWith('/notifications')) {
          return http.Response(jsonEncode({'notifications': [], 'non_lues': 0}), 200);
        }
        return api(req);
      });
      final auth = AuthProvider();
      await auth.init();
      await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: const AgriPlatformApp()));
      await tester.pumpAndSettle();
      await ouvrirOnglet(tester, const {'gestionnaire_entrepot': 'Stocks', 'producteur': 'Récoltes'}[utilisateur['role']] ?? '');
    }

    http.Response json(Object corps, [int statut = 200]) =>
        http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

    const producteur = {'id': 2, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'p@test.sn', 'role': 'producteur', 'statut': 'actif'};
    const gestionnaire = {'id': 3, 'nom': 'Sow', 'prenom': 'Ali', 'email': 'g@test.sn', 'role': 'gestionnaire_entrepot', 'statut': 'actif'};

    testWidgets('producteur : lot en entrepôt, seul le prix est modifiable et envoyé', (tester) async {
      await demarrer(tester, producteur, (req) async => req.method == 'PUT'
          ? json({'production': {}})
          : json({'productions': [
              {'id': 7, 'code_tracabilite': 'LOT-2026-AAAAAA', 'type_culture': 'riz', 'quantite_estimee': '100',
               'prix_unitaire': '150.00', 'statut': 'disponible', 'stocks': [{'id': 1}]},
            ]}));

      expect(find.text('💰 150 FCFA / kg'), findsOneWidget);
      await tester.tap(find.text('Modifier le prix'));
      await tester.pumpAndSettle();
      expect(find.text('Type de culture *'), findsNothing); // champs figés non proposés

      await tester.enterText(find.byKey(const Key('modifier-prix')), '180');
      await tester.tap(find.text('Sauvegarder'));
      await tester.pumpAndSettle();

      final put = requetes.singleWhere((r) => r.method == 'PUT');
      expect(put.url.path, endsWith('/productions/7'));
      expect(jsonDecode(put.body), {'prix_unitaire': 180});
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('gestionnaire : physique, réservé et disponible affichés', (tester) async {
      await demarrer(tester, gestionnaire, (req) async => req.url.path.endsWith('/alertes')
          ? json({'alertes': [], 'total': 0})
          : json({'stocks': [
              {'id': 1, 'produit': 'riz', 'quantite': '60', 'quantite_reservee': '10', 'quantite_disponible': '50', 'statut': 'disponible'},
            ]}));

      expect(find.text('50 kg disponibles'), findsOneWidget);
      expect(find.text('En entrepôt : 60 kg · Réservé : 10 kg'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    });
  });
}

/// Lot 5 : l'application s'ouvre sur « À faire » ; on passe à l'onglet voulu.
Future<void> ouvrirOnglet(WidgetTester tester, String libelle) async {
  final onglet = find.descendant(of: find.byType(NavigationBar), matching: find.text(libelle));
  if (onglet.evaluate().isEmpty) return;
  await tester.tap(onglet);
  await tester.pumpAndSettle();
}
