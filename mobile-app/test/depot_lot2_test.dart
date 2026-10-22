import 'dart:convert';

import 'package:agri_platform_mobile/screens/producteur/depots_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/nouveau_depot_screen.dart';
import 'package:agri_platform_mobile/screens/shared/profil_screen.dart';
import 'package:agri_platform_mobile/screens/transporteur/reservations_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/utils/chronologie.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 2 — circuit « récolte → entrepôt » sur l'application : les boutons
/// viennent des actions renvoyées par le serveur, chaque étape est confirmée.
void main() {
  late List<http.Request> requetes;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, String role, Widget ecran, Future<http.Response> Function(http.Request) api,
      {Map<String, dynamic>? profil}) async {
    requetes = [];
    tester.view.physicalSize = const Size(400, 1200);
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

  Map<String, dynamic> depot(String statut, List<String> actions, {String mode = 'transporteur'}) => {
    'id': 7, 'numero_reservation': 'RES-7', 'produit': 'mil', 'quantite_reservee': '40', 'statut': statut,
    'mode_transport': mode, 'transporteur_id': 3, 'actions': actions,
    'production': {'code_tracabilite': 'LOT-2026-AAAAAA'},
    'producteur': {'utilisateur': {'prenom': 'Awa', 'nom': 'Diallo', 'telephone': '770000000', 'adresse': 'Thiès'}},
    'entrepot': {'nom_entrepot': 'Nord'},
    'prochaine_etape': {'libelle': 'Mission à accepter par le transporteur'},
  };

  testWidgets('transporteur : refus avec motif obligatoire, puis acceptation confirmée', (tester) async {
    var liste = [depot('confirmee', ['accepter_mission', 'refuser_mission'])];
    await afficher(tester, 'transporteur', const ReservationsScreen(), (req) async {
      if (req.method == 'GET') return json({'reservations': liste});
      return json({'message': 'OK serveur'});
    });

    expect(find.text('Nouvelle mission à accepter'), findsOneWidget);
    expect(find.textContaining('Lot LOT-2026-AAAAAA'), findsOneWidget);
    expect(find.textContaining('770000000'), findsOneWidget);

    await tester.tap(find.byKey(const Key('depot-7-refuser_mission')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirmer'));
    await tester.pumpAndSettle();
    expect(find.text('Indiquez le motif.'), findsOneWidget);
    expect(requetes.where((r) => r.method == 'PUT'), isEmpty);
    await tester.enterText(find.byKey(const Key('motif')), 'Camion en panne');
    await tester.tap(find.text('Confirmer'));
    await tester.pumpAndSettle();
    final refus = requetes.singleWhere((r) => r.method == 'PUT');
    expect(refus.url.path, endsWith('/reservations/7/refuser-mission'));
    expect(jsonDecode(refus.body), {'motif': 'Camion en panne'});

    requetes.clear();
    await tester.tap(find.byKey(const Key('depot-7-accepter_mission')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Oui, confirmer'));
    await tester.pumpAndSettle();
    expect(requetes.singleWhere((r) => r.method == 'PUT').url.path, endsWith('/reservations/7/accepter-mission'));
    expect(find.text('OK serveur'), findsOneWidget);
  });

  testWidgets('producteur qui livre lui-même : « Je pars » envoie l\'étape en route après confirmation', (tester) async {
    await afficher(tester, 'producteur', const DepotsScreen(), (req) async {
      if (req.method == 'GET') return json({'reservations': [depot('confirmee', ['partir', 'annuler'], mode: 'producteur')]});
      return json({'message': 'Départ enregistré.'});
    });

    expect(find.text('Livré par le producteur'), findsOneWidget);
    await tester.tap(find.byKey(const Key('depot-7-partir')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Non'));
    await tester.pumpAndSettle();
    expect(requetes.where((r) => r.method == 'PUT'), isEmpty);

    await tester.tap(find.byKey(const Key('depot-7-partir')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Oui, confirmer'));
    await tester.pumpAndSettle();
    final req = requetes.singleWhere((r) => r.method == 'PUT');
    expect(req.url.path, endsWith('/reservations/7/etape'));
    expect(jsonDecode(req.body), {'etape': 'en_route'});
  });

  testWidgets('demande de dépôt depuis le téléphone : tout prérempli, le producteur choisit de livrer lui-même', (tester) async {
    await afficher(tester, 'producteur', const NouveauDepotScreen(), (req) async {
      if (req.url.path.endsWith('/entrepots/disponibles')) {
        return json({'entrepots': [{'id': 2, 'nom_entrepot': 'Nord', 'capacite_disponible': '500'}]});
      }
      if (req.url.path.endsWith('/productions')) {
        return json({'productions': [{'id': 9, 'code_tracabilite': 'LOT-1', 'type_culture': 'mil', 'quantite_estimee': '80', 'statut': 'en_attente'}]});
      }
      return json({'reservation': {'id': 1}}, 201);
    });

    // Lot 9 : tout est prérempli — dernière récolte, quantité reprise, entrepôt le plus proche, transporteur
    expect(find.text('LOT-1 — mil'), findsOneWidget);
    expect(find.text('80'), findsOneWidget);
    expect(find.textContaining('Nord'), findsOneWidget);
    expect(find.text('Où le transporteur vient-il chercher la marchandise ?'), findsOneWidget);

    await tester.tap(find.byKey(const Key('mode-producteur')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('depot-envoyer')));
    await tester.pumpAndSettle();
    final post = requetes.singleWhere((r) => r.method == 'POST');
    final corps = jsonDecode(post.body) as Map<String, dynamic>;
    expect([corps['entrepot_id'], corps['production_id'], corps['mode_transport'], corps['quantite_reservee']], [2, 9, 'producteur', 80]);
    expect(post.headers['Idempotency-Key'], isNotNull);
  });

  testWidgets('profil : la position touchée sur la carte est enregistrée', (tester) async {
    await afficher(tester, 'producteur', const ProfilScreen(afficherTuiles: false), (req) async => json({'utilisateur': {}}),
        profil: {'producteur': {'latitude': '14.7', 'longitude': '-17.4'}});

    expect(find.text('Position de votre exploitation (lieu de collecte)'), findsOneWidget);
    expect(find.textContaining('14.70000, -17.40000'), findsOneWidget);
    await tester.tap(find.byType(FlutterMap));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('Enregistrer les modifications'));
    await tester.tap(find.text('Enregistrer les modifications'));
    await tester.pumpAndSettle();
    final corps = jsonDecode(requetes.singleWhere((r) => r.method == 'PUT').body) as Map<String, dynamic>;
    expect((corps['latitude'] as num).toDouble(), closeTo(14.7, 0.05));
    expect((corps['longitude'] as num).toDouble(), closeTo(-17.4, 0.05));
  });

  test('chronologie : étapes de l\'acheminement, refus de réception en alerte', () {
    final etapes = construireChronologie({
      'etape_production': {'type_culture': 'mil', 'date_recolte': '2026-10-01', 'quantite_estimee': 80},
      'etapes_acheminement': [
        {'entrepot': {'nom': 'Nord'}, 'statut': 'en_route', 'quantite_prevue': 40, 'transporteur': {'prenom': 'Ibra', 'nom': 'Test'},
         'etapes': [
           {'action': 'creation', 'libelle': 'Dépôt demandé', 'date': '2026-10-02T08:00:00Z'},
           {'action': 'en_route', 'libelle': 'En route vers l\'entrepôt', 'date': '2026-10-02T10:00:00Z'},
         ]},
        {'entrepot': {'nom': 'Sud'}, 'statut': 'reception_refusee',
         'etapes': [{'action': 'refus_reception', 'libelle': 'Réception refusée', 'date': '2026-10-03T10:00:00Z'}]},
      ],
    });
    expect(etapes.map((e) => e.titre).toList(), ['Récolte', 'Dépôt demandé', 'En route vers l\'entrepôt', 'Réception refusée']);
    expect(etapes[2].etat, EtatEtape.actuel);
    expect(etapes[2].detail, 'Transporteur : Ibra Test');
    expect(etapes[3].etat, EtatEtape.alerte);
  });
}
