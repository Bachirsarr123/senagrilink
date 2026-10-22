import 'dart:convert';

import 'package:agri_platform_mobile/screens/acheteur/catalogue_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/services/position_appareil.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 13 : commander et payer d'un seul appui depuis le catalogue.
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, Future<http.Response> Function(http.Request) api) async {
    requetes = [];
    PositionAppareil.lire = () async => null;
    tester.view.physicalSize = const Size(420, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Sow', 'prenom': 'Fatou', 'email': 'a@test.sn', 'role': 'acheteur_gros', 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: const MaterialApp(home: CatalogueScreen())));
    await tester.pumpAndSettle();
  }

  const catalogue = {'catalogue': [{'stock_id': 4, 'produit': 'riz', 'quantite_disponible': '50', 'prix_unitaire': '150'}]};
  const paiement = {'reference': 'PAY-1', 'montant': 2700, 'moyen': 'wave', 'fournisseur': 'simulation', 'telephone': '771234567', 'commandes': []};

  Future<void> choisirQuantite(WidgetTester tester) async {
    await tester.tap(find.text('Commander').first);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('commande-quantite')), '10');
    await tester.pumpAndSettle();
  }

  testWidgets('« Payer avec Wave » : commande passée et écran de paiement ouvert du même appui', (tester) async {
    var statut = 'initie';
    await afficher(tester, (req) async {
      final p = req.url.path;
      if (req.method == 'POST' && p.endsWith('/commandes')) {
        return json({'message': 'Confirmez le paiement de 2 700 FCFA avec Wave pour valider la commande.', 'commande': {'id': 8},
          'paiement': {'reference': 'PAY-1', 'moyen': 'wave', 'statut': 'initie'}}, 201);
      }
      if (p.endsWith('/simulation')) statut = 'reussi';
      if (p.contains('/paiements/')) {
        return json({'message': 'Paiement de 2 700 FCFA réussi : commande validée, l\'entrepôt la prépare.', 'paiement': {...paiement, 'statut': statut}});
      }
      return json(p.endsWith('/devis') ? {'devis': null} : catalogue);
    });
    await choisirQuantite(tester);
    expect(find.text('Confirmer la commande'), findsNothing);
    await tester.tap(find.byKey(const Key('commander-wave')));
    await tester.pumpAndSettle();

    final post = requetes.singleWhere((r) => r.method == 'POST' && r.url.path.endsWith('/commandes'));
    expect(jsonDecode(post.body), containsPair('moyen', 'wave'));
    expect(find.textContaining('validée dès le paiement'), findsOneWidget);
    await tester.tap(find.byKey(const Key('confirmer-paiement')));
    await tester.pumpAndSettle();
    expect(find.textContaining('commande validée'), findsOneWidget);
    await tester.tap(find.text('Retour à mes commandes'));
    await tester.pumpAndSettle();
    expect(find.byType(SnackBar), findsOneWidget); // le résultat reste affiché au retour
  });

  testWidgets('Espèces : commande validée tout de suite, montant à remettre au transporteur', (tester) async {
    await afficher(tester, (req) async {
      if (req.method == 'POST') {
        return json({'message': 'Commande passée. Vous paierez 2 700 FCFA en espèces au transporteur, à la livraison.', 'commande': {'id': 8},
          'paiement': {'reference': 'PAY-2', 'moyen': 'especes'}}, 201);
      }
      return json(req.url.path.endsWith('/devis') ? {'devis': null} : catalogue);
    });
    await choisirQuantite(tester);
    await tester.tap(find.byKey(const Key('commander-especes')));
    await tester.pumpAndSettle();
    expect(jsonDecode(requetes.singleWhere((r) => r.method == 'POST').body), containsPair('moyen', 'especes'));
    expect(find.textContaining('Vous paierez 2 700 FCFA en espèces'), findsOneWidget);
    expect(find.textContaining('Simulation'), findsNothing); // pas d'écran de paiement
  });
}
