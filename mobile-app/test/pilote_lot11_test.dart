import 'dart:convert';

import 'package:agri_platform_mobile/screens/gestionnaire/agriculteurs_screen.dart';
import 'package:agri_platform_mobile/screens/gestionnaire/ma_journee_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 11 : pilote automatique, « Ma journée » et agriculteurs du gestionnaire.
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, Widget ecran, Future<http.Response> Function(http.Request) api) async {
    requetes = [];
    tester.view.physicalSize = const Size(420, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Ndiaye', 'prenom': 'Ibra', 'email': 'g@test.sn', 'role': 'gestionnaire_entrepot', 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  final journee = {
    'attention': [],
    'arrivent': [{'id': 3, 'numero': 'RES-3', 'produit': 'mil', 'quantite': 40, 'statut': 'en_route', 'etape': 'En route vers l\'entrepôt',
      'producteur': {'nom': 'Awa Diallo'}, 'transporteur': {'nom': 'Alioune Fall'}, 'livre_lui_meme': false, 'a_receptionner': false}],
    'a_preparer': [{'id': 8, 'numero': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'statut': 'confirmee', 'acheteur': {'nom': 'Fatou Sow'}, 'action': 'prete'}],
    'partent': [],
    'pilote': {'auto_accepter_depots': true, 'auto_confirmer_commandes': true, 'auto_affecter_transporteurs': true, 'delai_reponse_min': 10},
  };

  testWidgets('Ma journée : rien à traiter, arrivées, « Commande prête » d\'un appui, réglages du pilote', (tester) async {
    await afficher(tester, const MaJourneeScreen(), (req) async {
      if (req.url.path.endsWith('/journee')) return json(journee);
      if (req.url.path.endsWith('/prete')) return json({'message': 'Commande prête. Un transporteur a été prévenu automatiquement.'});
      return json({'utilisateur': {}});
    });
    expect(find.text('Rien ne demande votre attention'), findsOneWidget);
    expect(find.textContaining('Awa Diallo — mil'), findsOneWidget);
    expect(find.text('🚚 Alioune Fall'), findsOneWidget);

    await tester.tap(find.byKey(const Key('prete-8')));
    await tester.pumpAndSettle();
    expect(requetes.where((r) => r.method == 'PUT').map((r) => r.url.path.split('/api').last), ['/commandes/8/preparer', '/commandes/8/prete']);
    expect(find.text('Commande prête. Un transporteur a été prévenu automatiquement.'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('pilote-depots')));
    await tester.tap(find.byKey(const Key('pilote-depots')));
    await tester.pumpAndSettle();
    final profil = requetes.lastWhere((r) => r.url.path.endsWith('/profile'));
    expect(jsonDecode(profil.body), {'auto_accepter_depots': false});
  });

  testWidgets('Agriculteurs : recherche, puis fiche', (tester) async {
    await afficher(tester, const AgriculteursScreen(), (req) async {
      if (req.url.path.endsWith('/producteurs/2')) {
        return json({'producteur': {'id': 2, 'nom': 'Awa Diallo', 'telephone': '771112233', 'region': 'Thiès'},
          'stocks': [{'id': 1, 'produit': 'riz', 'quantite_disponible': 70, 'lot': 'LOT-1'}],
          'depots': [{'id': 3, 'numero_reservation': 'RES-3', 'produit': 'riz', 'quantite_reservee': 80, 'statut': 'enregistree'}],
          'ventes': [{'id': 8, 'numero_commande': 'CMD-8', 'quantite': 10, 'prix': 1500, 'statut': 'livree'}]});
      }
      final q = req.url.queryParameters['q'] ?? '';
      return json({'producteurs': q == 'awa' || q.isEmpty
          ? [{'id': 2, 'nom': 'Awa Diallo', 'telephone': '771112233', 'region': 'Thiès', 'stock_kg': 80, 'depots_en_cours': 1}] : []});
    });
    await tester.enterText(find.byKey(const Key('recherche-agriculteur')), 'awa');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(requetes.last.url.queryParameters['q'], 'awa');
    await tester.tap(find.text('Awa Diallo'));
    await tester.pumpAndSettle();
    expect(find.textContaining('70 kg disponibles'), findsOneWidget);
    expect(find.textContaining('RES-3'), findsOneWidget);
    expect(find.textContaining('CMD-8'), findsOneWidget);
  });
}
