import 'dart:convert';

import 'package:agri_platform_mobile/screens/acheteur/catalogue_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/nouveau_depot_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/services/position_appareil.dart';
import 'package:agri_platform_mobile/widgets/choix_position.dart';
import 'package:agri_platform_mobile/widgets/carte_depot.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 8 : lieux pris automatiquement (téléphone ou lieu habituel), modifiables sur la carte.
void main() {
  late List<http.Request> requetes;
  const ici = LatLng(14.75, -17.38);

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, String role, Widget ecran, Future<http.Response> Function(http.Request) api,
      {Map<String, dynamic>? profil}) async {
    requetes = [];
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'a@test.sn', 'role': role, 'statut': 'actif', ...?profil,
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  testWidgets('carte de position : le téléphone donne sa position tout seul', (tester) async {
    PositionAppareil.lire = () async => ici;
    final emis = <LatLng?>[];
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ChoixPosition(libelle: 'Lieu', auto: true, onChange: emis.add))));
    await tester.pumpAndSettle();
    expect(emis.single, ici);
    expect(find.textContaining('Votre position actuelle'), findsOneWidget);
  });

  testWidgets('autorisation refusée : rien n\'est redemandé, le lieu se place sur la carte', (tester) async {
    var lectures = 0;
    PositionAppareil.lire = () async { lectures++; return null; };
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ChoixPosition(libelle: 'Lieu', auto: true, onChange: (_) {}))));
    await tester.pumpAndSettle();
    expect(lectures, 1);
    expect(find.textContaining('touchez la carte à l\'endroit exact'), findsOneWidget);
  });

  testWidgets('dépôt : entrepôts les plus proches d\'abord, lieu de prise en charge envoyé', (tester) async {
    PositionAppareil.lire = () async => ici;
    await afficher(tester, 'producteur', const NouveauDepotScreen(), (req) async {
      if (req.url.path.endsWith('/entrepots/disponibles')) {
        return json({'entrepots': [{'id': 2, 'nom_entrepot': 'Thiès', 'capacite_disponible': '500', 'distance_km': 3.2}]});
      }
      if (req.url.path.endsWith('/productions')) {
        return json({'productions': [{'id': 9, 'code_tracabilite': 'LOT-1', 'type_culture': 'mil', 'quantite_estimee': '80', 'statut': 'en_attente'}]});
      }
      return json({'reservation': {'id': 1}}, 201);
    });
    final liste = requetes.firstWhere((r) => r.url.path.endsWith('/entrepots/disponibles'));
    expect(liste.url.queryParameters, {'latitude': '14.75', 'longitude': '-17.38'});

    await tester.tap(find.byKey(const Key('depot-recolte')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('LOT-1 — mil').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('depot-entrepot')));
    await tester.pumpAndSettle();
    expect(find.textContaining('à 3,2 km'), findsWidgets);
    await tester.tap(find.textContaining('Thiès').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('mode-transporteur')));
    await tester.pumpAndSettle();
    expect(find.text('Où le transporteur vient-il chercher la marchandise ?'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('depot-envoyer')));
    await tester.tap(find.byKey(const Key('depot-envoyer')));
    await tester.pumpAndSettle();
    final corps = jsonDecode(requetes.singleWhere((r) => r.method == 'POST').body) as Map<String, dynamic>;
    expect([corps['collecte_latitude'], corps['collecte_longitude']], [14.75, -17.38]);
  });

  testWidgets('catalogue : commande livrée à la position actuelle du téléphone', (tester) async {
    PositionAppareil.lire = () async => ici;
    await afficher(tester, 'acheteur_gros', const CatalogueScreen(), (req) async {
      if (req.method == 'POST') return json({'message': 'Panier commandé.'}, 201);
      return json({'catalogue': [{'stock_id': 4, 'produit': 'riz', 'quantite_disponible': '50', 'prix_unitaire': '150'}]});
    }, profil: {'acheteur_gros': {'latitude': '14.6', 'longitude': '-17.5'}});
    expect(find.text('Livraison à votre position actuelle'), findsOneWidget);

    await tester.tap(find.text('Commander').first);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('commande-quantite')), '5');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('commander-especes')));
    await tester.pumpAndSettle();
    final corps = jsonDecode(requetes.singleWhere((r) => r.method == 'POST').body) as Map<String, dynamic>;
    expect([corps['livraison_latitude'], corps['livraison_longitude']], [14.75, -17.38]);
  });

  testWidgets('transporteur : guidé dans l\'application vers le producteur', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: CarteDepot(
      reservation: {
        'id': 7, 'numero_reservation': 'RES-7', 'produit': 'mil', 'quantite_reservee': '40', 'statut': 'prise_en_charge',
        'mode_transport': 'transporteur', 'actions': <String>[], 'collecte_latitude': 14.75, 'collecte_longitude': -17.38,
        'producteur': {'latitude': null, 'longitude': null}, 'entrepot': {'nom_entrepot': 'Thiès'},
      },
      actionsAffichees: const [],
      vueTransporteur: true,
      onAction: (_) {},
    )))));
    await tester.pumpAndSettle();
    expect(find.text('Conduire jusqu\'au producteur'), findsOneWidget);
  });
}
