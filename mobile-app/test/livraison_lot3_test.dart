import 'dart:convert';

import 'package:agri_platform_mobile/models/livraison.dart';
import 'package:agri_platform_mobile/screens/acheteur/catalogue_screen.dart';
import 'package:agri_platform_mobile/screens/acheteur/mes_commandes_screen.dart';
import 'package:agri_platform_mobile/screens/transporteur/detail_livraison_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 3 — circuit « entrepôt → acheteur » sur l'application : boutons donnés
/// par le serveur, remise avec le code, incident, réception, panier.
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, String role, Widget ecran, Future<http.Response> Function(http.Request) api) async {
    requetes = [];
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Fall', 'prenom': 'Moussa', 'email': 'm@test.sn', 'role': role, 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> livraison(String statut, List<String> actions, {List<Map<String, dynamic>> incidents = const []}) => {
    'id': 4, 'numero_livraison': 'LIV-4', 'statut': statut, 'origine': 'Entrepôt Nord', 'destination': 'Dakar Plateau',
    'actions': actions, 'incidents': incidents, 'prochaine_etape': {'libelle': 'Étape test'},
    'commande': {'numero_commande': 'CMD-9', 'produit': 'riz', 'quantite': '10',
      'acheteur': {'utilisateur': {'prenom': 'Awa', 'nom': 'Diop', 'telephone': '770000000'}},
      'stock': {'entrepot': {'nom_entrepot': 'Entrepôt Nord', 'utilisateur': {'telephone': '781112233'}}}},
  };

  testWidgets('transporteur : chargement confirmé, puis remise avec un code à 4 chiffres', (tester) async {
    var courante = livraison('prise_en_charge', ['charger', 'declarer_incident']);
    await afficher(tester, 'transporteur', DetailLivraisonScreen(livraison: Livraison.fromJson(courante)), (req) async {
      if (req.method == 'GET') return json({'livraisons': [courante]});
      if (req.url.path.endsWith('/etape')) { courante = livraison('arrivee', ['confirmer_par_code', 'declarer_incident']); }
      if (req.url.path.endsWith('/remise')) {
        if (jsonDecode(req.body)['code'] != '0427') return json({'message': 'Code de réception incorrect.'}, 422);
        courante = livraison('livree', []);
      }
      return json({'message': 'OK serveur'});
    });

    expect(find.textContaining('Awa Diop · 770000000'), findsOneWidget);
    expect(find.textContaining('781112233'), findsOneWidget);
    expect(find.text('Remettre avec le code'), findsNothing);

    await tester.tap(find.byKey(const Key('livraison-charger')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Non'));
    await tester.pumpAndSettle();
    expect(requetes.where((r) => r.method == 'PUT'), isEmpty);

    await tester.tap(find.byKey(const Key('livraison-charger')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Oui, confirmer'));
    await tester.pumpAndSettle();
    expect(jsonDecode(requetes.firstWhere((r) => r.method == 'PUT').body), {'etape': 'chargee'});
    expect(find.text('Remettre avec le code'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code-reception')), '12');
    await tester.tap(find.byKey(const Key('remettre')));
    await tester.pumpAndSettle();
    expect(find.text('Le code de réception comporte 4 chiffres.'), findsOneWidget);
    expect(requetes.where((r) => r.url.path.endsWith('/remise')), isEmpty);

    await tester.enterText(find.byKey(const Key('code-reception')), '1111');
    await tester.tap(find.byKey(const Key('remettre')));
    await tester.pumpAndSettle();
    expect(find.text('Code de réception incorrect.'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code-reception')), '0427');
    await tester.tap(find.byKey(const Key('remettre')));
    await tester.pumpAndSettle();
    expect(find.text('Remettre avec le code'), findsNothing);
    expect(find.text('Livrée'), findsOneWidget);
  });

  testWidgets('transporteur : incident avec type et description obligatoires ; transport suspendu', (tester) async {
    var courante = livraison('en_cours', ['arriver', 'declarer_incident']);
    await afficher(tester, 'transporteur', DetailLivraisonScreen(livraison: Livraison.fromJson(courante)), (req) async {
      if (req.method == 'GET') return json({'livraisons': [courante]});
      courante = livraison('en_cours', ['declarer_incident'], incidents: [{'id': 1, 'type': 'panne', 'statut': 'ouvert'}]);
      return json({'message': 'Incident déclaré.'}, 201);
    });

    await tester.tap(find.byKey(const Key('livraison-declarer_incident')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Envoyer'));
    await tester.pumpAndSettle();
    expect(find.text('Choisissez le type et décrivez la situation.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('type-incident')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Panne du véhicule').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('description-incident')), 'Pneu crevé');
    await tester.tap(find.text('Envoyer'));
    await tester.pumpAndSettle();

    final envoi = requetes.firstWhere((r) => r.method == 'POST');
    expect(envoi.url.path, endsWith('/incidents'));
    expect(jsonDecode(envoi.body), {'sujet_type': 'livraison', 'sujet_id': 4, 'type': 'panne', 'description': 'Pneu crevé'});
    expect(envoi.headers['Idempotency-Key'], isNotNull);
    expect(find.textContaining('Incident : Panne du véhicule'), findsOneWidget);
    expect(find.byKey(const Key('livraison-arriver')), findsNothing);
  });

  testWidgets('acheteur : code de réception affiché, réception confirmée, boutons du serveur seulement', (tester) async {
    var statut = 'en_livraison';
    Map<String, dynamic> commande() => {
      'id': 8, 'numero_commande': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'statut': statut,
      'code_reception': statut == 'en_livraison' ? '0427' : null,
      'actions': statut == 'en_livraison' ? ['confirmer_reception', 'declarer_anomalie'] : <String>[],
      'livraison': {'id': 2, 'statut': statut == 'en_livraison' ? 'arrivee' : 'livree'}, 'incidents': [],
    };
    await afficher(tester, 'acheteur_gros', const MesCommandesScreen(), (req) async {
      if (req.method == 'GET') return json({'commandes': [commande()]});
      statut = 'receptionnee';
      return json({'message': 'Réception confirmée. Merci !'});
    });

    expect(find.textContaining('0427'), findsOneWidget);
    expect(find.text('Modifier'), findsNothing);
    await tester.tap(find.byKey(const Key('reception-8')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Oui, confirmer'));
    await tester.pumpAndSettle();

    expect(requetes.firstWhere((r) => r.method == 'PUT').url.path, endsWith('/commandes/8/reception'));
    expect(find.textContaining('0427'), findsNothing);
    expect(find.byKey(const Key('reception-8')), findsNothing);
  });

  testWidgets('acheteur : panier de deux produits commandé en une fois', (tester) async {
    final produits = [
      {'stock_id': 1, 'produit': 'riz', 'quantite_disponible': 40, 'prix_unitaire': 150},
      {'stock_id': 2, 'produit': 'mil', 'quantite_disponible': 30, 'prix_unitaire': 200},
    ];
    await afficher(tester, 'acheteur_gros', const CatalogueScreen(), (req) async {
      if (req.method == 'GET') return json({'catalogue': produits});
      return json({'message': 'Panier commandé : 2 commandes passées.', 'commandes': [], 'panier': 'PAN-1'}, 201);
    });

    for (final (i, q) in [(0, '20'), (1, '5')]) {
      await tester.tap(find.text('Commander').at(i));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('commande-quantite')), q);
      await tester.tap(find.byKey(const Key('ajouter-panier')));
      await tester.pumpAndSettle();
    }
    expect(find.textContaining('Panier (2)'), findsOneWidget);

    await tester.tap(find.byKey(const Key('panier')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('commander-especes')));
    await tester.pumpAndSettle();

    final envoi = requetes.firstWhere((r) => r.method == 'POST');
    expect(jsonDecode(envoi.body), {'lignes': [{'stock_id': 1, 'quantite': 20.0}, {'stock_id': 2, 'quantite': 5.0}], 'moyen': 'especes'});
    expect(find.textContaining('Panier ('), findsNothing);
    expect(find.textContaining('2 commandes passées'), findsOneWidget);
  });
}
