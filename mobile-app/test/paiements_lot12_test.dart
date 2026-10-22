import 'dart:convert';

import 'package:agri_platform_mobile/screens/acheteur/mes_commandes_screen.dart';
import 'package:agri_platform_mobile/screens/paiement/argent_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 12 : paiements simulés comme des vrais, « Mon argent ».
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, String role, Widget ecran, Future<http.Response> Function(http.Request) api) async {
    requetes = [];
    tester.view.physicalSize = const Size(420, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Sow', 'prenom': 'Fatou', 'email': 'a@test.sn', 'role': role, 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  const commande = {'id': 8, 'numero_commande': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'prix': 1500, 'prix_unitaire': 150,
    'frais_livraison': 1200, 'total_a_payer': 2700, 'statut': 'confirmee', 'statut_paiement': 'a_payer', 'actions': [], 'incidents': []};

  testWidgets('acheteur : « Payer » avec Wave, page de paiement simulée, argent mis de côté', (tester) async {
    var statut = 'initie';
    await afficher(tester, 'acheteur_gros', const MesCommandesScreen(), (req) async {
      final p = req.url.path;
      if (p.endsWith('/historique')) return json({'commandes': [commande]});
      if (req.method == 'POST' && p.endsWith('/paiements')) return json({'paiement': {'reference': 'PAY-1'}}, 201);
      if (p.endsWith('/simulation')) { statut = 'reussi'; }
      return json({'message': 'Paiement de 2 700 FCFA réussi. L\'argent est mis de côté jusqu\'à la réception.',
        'paiement': {'reference': 'PAY-1', 'montant': 2700, 'moyen': 'wave', 'fournisseur': 'simulation', 'statut': statut, 'telephone': '771234567', 'commandes': [commande]}});
    });
    expect(find.textContaining('À payer : 2'), findsOneWidget);
    await tester.tap(find.byKey(const Key('payer-8')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('payer-wave')));
    await tester.pumpAndSettle();
    final post = requetes.singleWhere((r) => r.method == 'POST' && r.url.path.endsWith('/paiements'));
    expect(jsonDecode(post.body), {'commandes': [8], 'moyen': 'wave'});
    expect(post.headers['Idempotency-Key'], isNotNull);
    expect(find.textContaining('Simulation : aucun argent réel'), findsOneWidget);

    await tester.tap(find.byKey(const Key('confirmer-paiement')));
    await tester.pumpAndSettle();
    expect(jsonDecode(requetes.last.body), {'resultat': 'reussi'});
    expect(find.textContaining('mis de côté'), findsWidgets);
  });

  testWidgets('acheteur : payer en espèces à la livraison, sans page de paiement', (tester) async {
    await afficher(tester, 'acheteur_gros', const MesCommandesScreen(), (req) async {
      if (req.url.path.endsWith('/historique')) return json({'commandes': [commande]});
      return json({'message': 'Vous paierez en espèces au transporteur, à la livraison.', 'paiement': {'reference': 'PAY-2'}}, 201);
    });
    await tester.tap(find.byKey(const Key('payer-8')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('payer-especes')));
    await tester.pumpAndSettle();
    expect(find.text('Vous paierez en espèces au transporteur, à la livraison.'), findsOneWidget);
  });

  testWidgets('producteur : Mon argent, détail d\'une vente, numéro Wave enregistré', (tester) async {
    await afficher(tester, 'producteur', const ArgentScreen(), (req) async {
      if (req.url.path.endsWith('/argent')) {
        return json({'totaux': {'mis_de_cote': 1420, 'verse': 2840, 'a_regler': 0, 'regle': 0}, 'moyen_paiement': 'wave', 'numero_paiement': '771112233',
          'versements': [{'id': 1, 'beneficiaire': 'producteur', 'montant': 1420, 'statut': 'verse',
            'commande': {'numero_commande': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'prix': 1500, 'frais_stockage': 50, 'commission': 30}}]});
      }
      return json({'utilisateur': {}});
    });
    expect(find.textContaining('entrepôt 50'), findsOneWidget);
    expect(find.text('Versé', findRichText: true), findsNothing);
    await tester.enterText(find.byKey(const Key('numero-paiement')), '781234567');
    await tester.tap(find.byKey(const Key('enregistrer-numero')));
    await tester.pumpAndSettle();
    expect(jsonDecode(requetes.last.body), {'moyen_paiement': 'wave', 'numero_paiement': '781234567'});
    expect(find.text('Numéro de réception enregistré.'), findsOneWidget);
  });
}
