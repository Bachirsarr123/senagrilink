import 'dart:async';
import 'dart:convert';

import 'package:agri_platform_mobile/screens/acheteur/formulaire_commande.dart';
import 'package:agri_platform_mobile/screens/conduite/conduite_screen.dart';
import 'package:agri_platform_mobile/screens/shared/a_faire_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/services/suivi_gps_service.dart';
import 'package:agri_platform_mobile/utils/action_directe.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 9 : guidage dans l'application (consignes, voix, gros bouton) et moins d'appuis.
void main() {
  late List<http.Request> requetes;
  late List<String> dit;

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  Future<void> afficher(WidgetTester tester, String role, Widget ecran, Future<http.Response> Function(http.Request) api) async {
    requetes = [];
    tester.view.physicalSize = const Size(400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
      'id': 1, 'nom': 'Ndiaye', 'prenom': 'Moussa', 'email': 't@test.sn', 'role': role, 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  setUp(() {
    dit = [];
    ConduiteScreen.parler = (t) async => dit.add(t);
    ConduiteScreen.delaiAnnulation = const Duration(milliseconds: 300);
    AFaireScreen.delaiAnnulation = const Duration(milliseconds: 300);
    SuiviGpsService.autorisation = () async => true;
    SuiviGpsService.sourcePositions = () => const Stream<Position>.empty();
  });

  Map<String, dynamic> navigation({bool proche = false, List<String> actions = const ['charger'], String statut = 'prise_en_charge',
      String principale = 'demarrer', bool automatique = false, String type = 'livraison'}) => {
        'sujet_type': type, 'sujet_id': 5, 'numero': 'LIV-5', 'statut': statut, 'vers': 'origine',
        'cible': {'nom': 'Entrepôt Thiès', 'adresse': 'Route de Dakar', 'latitude': 14.79, 'longitude': -16.93},
        'actions': actions, 'proche': proche, 'action_principale': principale, 'arrivee_automatique': automatique,
        'itineraire': {
          'km': 2.3, 'secondes': 300, 'routier': true, 'arrivee_vers': '2026-10-22T10:05:00Z',
          'points': [[14.80, -16.95], [14.795, -16.94], [14.79, -16.93]],
          'etapes': [
            {'instruction': 'Partez par Avenue Senghor', 'type': 'depart', 'distance_m': 1200, 'debut': 0, 'fin': 1},
            {'instruction': 'Tournez à droite sur Route de Dakar', 'type': 'droite', 'distance_m': 1100, 'debut': 1, 'fin': 2},
            {'instruction': 'Arrivée à destination', 'type': 'arrivee', 'distance_m': 0, 'debut': 2, 'fin': 2},
          ],
        },
      };

  testWidgets('conduite : consigne en gros et à voix haute, heure d\'arrivée, gros bouton d\'étape', (tester) async {
    final positions = StreamController<LatLng>();
    SuiviGpsService.autorisation = () async => false; // pas de partage réel dans ce test
    ConduiteScreen.sourcePositions = () => positions.stream;
    var proche = false;
    await afficher(tester, 'transporteur', const ConduiteScreen(type: 'livraison', id: 5, numero: 'LIV-5'), (req) async {
      if (req.url.path.contains('/navigation/livraison/5')) return json(navigation(proche: proche));
      return json({'message': 'Chargement enregistré.'});
    });

    positions.add(const LatLng(14.80, -16.95));
    await tester.pumpAndSettle();
    expect(requetes.first.url.queryParameters, {'latitude': '14.8', 'longitude': '-16.95'});
    expect(find.text('Tournez à droite sur Route de Dakar'), findsOneWidget);
    expect(find.textContaining('Dans '), findsOneWidget);
    expect(find.textContaining('Entrepôt Thiès'), findsOneWidget);
    expect(find.textContaining('5 min · 2,3 km'), findsOneWidget);
    expect(dit.single, startsWith('Dans '));
    expect(dit.single, endsWith('Tournez à droite sur Route de Dakar'));
    expect(find.text('Démarrer'), findsOneWidget);

    // Voix coupée : plus rien n'est dit
    await tester.tap(find.byKey(const Key('conduite-voix')));
    await tester.pumpAndSettle();
    expect((await SharedPreferences.getInstance()).getBool('conduite_voix'), false);

    // Appui sur le gros bouton, puis annulation : rien n'est envoyé
    ConduiteScreen.delaiAnnulation = const Duration(seconds: 3);
    await tester.tap(find.byKey(const Key('conduite-action')));
    await tester.pump(const Duration(milliseconds: 500)); // bandeau affiché
    expect(find.text('ANNULER'), findsOneWidget);
    ScaffoldMessenger.of(tester.element(find.byType(ConduiteScreen))).hideCurrentSnackBar(reason: SnackBarClosedReason.action); // « ANNULER »
    await tester.pumpAndSettle();
    expect(requetes.where((r) => r.method == 'PUT'), isEmpty);
    ConduiteScreen.delaiAnnulation = const Duration(milliseconds: 300);

    // Arrivé : « Vous êtes arrivé ? », appui confirmé après le délai
    proche = true;
    await tester.tap(find.byKey(const Key('conduite-action')));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    // « Démarrer » : chargement puis départ, en un appui
    final puts = requetes.where((r) => r.method == 'PUT').toList();
    expect(puts.map((r) => r.url.path.endsWith('/livraisons/5/etape')), [true, true]);
    expect(puts.map((r) => jsonDecode(r.body)['etape']), ['chargee', 'en_cours']);
    expect(find.byKey(const Key('conduite-proche')), findsOneWidget);
    expect(demarrePartage('demarrer'), isTrue); // au départ, la position est partagée (vérifié au lot 4)
    await positions.close();
  });

  testWidgets('sans lieu connu : message clair au lieu du trajet', (tester) async {
    ConduiteScreen.sourcePositions = () => Stream.value(const LatLng(14.8, -16.95));
    await afficher(tester, 'transporteur', const ConduiteScreen(type: 'reservation', id: 3, numero: 'RES-3'), (req) async => json({
      ...navigation(actions: const ['arriver_collecte'], principale: 'arriver_collecte'), 'itineraire': null,
      'message': 'Le lieu exact de prise en charge n\'est pas encore connu : appelez votre contact.',
    }));
    expect(find.textContaining('appelez votre contact'), findsOneWidget);
    expect(find.text('Je suis chez le producteur'), findsOneWidget);
  });

  testWidgets('À faire : confier au transporteur proposé d\'un appui', (tester) async {
    await afficher(tester, 'gestionnaire_entrepot', const AFaireScreen(), (req) async {
      if (req.url.path.endsWith('/a-faire')) {
        return json({'elements': [{
          'categorie': 'commande', 'sujet_type': 'commande', 'sujet_id': 8, 'numero': 'CMD-8', 'titre': 'riz — 10 kg',
          'etape': 'Organiser la livraison', 'depuis': null, 'urgence': 'normale', 'action': 'organiser_livraison',
          'transporteur_propose': {'id': 4, 'nom': 'Awa Fall', 'distance_km': 1.5},
        }]});
      }
      return json({'message': 'Livraison organisée.'}, 201);
    });
    expect(find.text('Confier à Awa Fall (à 1,5 km)'), findsOneWidget);
    await tester.tap(find.byKey(const Key('a-faire-action-commande-8')));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    final post = requetes.singleWhere((r) => r.method == 'POST');
    expect(jsonDecode(post.body), {'commande_id': 8, 'transporteur_id': 4});
  });

  testWidgets('commande : quantité choisie avec − / +, sans clavier', (tester) async {
    await afficher(tester, 'acheteur_gros', Scaffold(body: FormulaireCommande(produit: const {'stock_id': 4, 'produit': 'riz', 'quantite_disponible': '50', 'prix_unitaire': '150'})), (req) async => json({}));
    await tester.tap(find.byKey(const Key('commande-plus')));
    await tester.tap(find.byKey(const Key('commande-plus')));
    await tester.tap(find.byKey(const Key('commande-moins')));
    await tester.pumpAndSettle();
    expect(find.text('10'), findsOneWidget);
    expect(find.byKey(const Key('commande-total')), findsOneWidget);
  });

  testWidgets('arrivé chez le producteur : l\'étape s\'enregistre toute seule, sans appui', (tester) async {
    ConduiteScreen.sourcePositions = () => Stream.value(const LatLng(14.85, -16.80));
    var arrive = false;
    await afficher(tester, 'transporteur', const ConduiteScreen(type: 'reservation', id: 5, numero: 'RES-5'), (req) async {
      if (req.method == 'PUT') { arrive = true; return json({'message': 'Étape enregistrée.'}); }
      return json(arrive
          ? navigation(type: 'reservation', actions: const ['charger'], principale: 'demarrer', statut: 'au_point_collecte')
          : navigation(type: 'reservation', actions: const ['arriver_collecte'], principale: 'arriver_collecte', proche: true, automatique: true));
    });
    await tester.pumpAndSettle();
    final put = requetes.singleWhere((r) => r.method == 'PUT');
    expect(put.url.path, endsWith('/reservations/5/etape'));
    expect(jsonDecode(put.body), {'etape': 'au_point_collecte'});
    expect(find.text('Démarrer'), findsOneWidget);
    expect(dit.last, contains('Chargez la marchandise'));
  });

  testWidgets('À faire : « Accepter et confier » enchaîne acceptation et affectation', (tester) async {
    await afficher(tester, 'gestionnaire_entrepot', const AFaireScreen(), (req) async {
      if (req.url.path.endsWith('/a-faire')) {
        return json({'elements': [{
          'categorie': 'depot', 'sujet_type': 'reservation', 'sujet_id': 3, 'numero': 'RES-3', 'titre': 'mil — 40 kg',
          'etape': 'Dépôt à examiner', 'depuis': null, 'urgence': 'normale', 'action': 'accepter_et_confier', 'statut': 'en_attente',
          'transporteur_propose': {'id': 4, 'nom': 'Awa Fall', 'distance_km': null},
        }]});
      }
      return json({'message': 'Transporteur affecté.'});
    });
    expect(find.text('Accepter et confier à Awa Fall'), findsOneWidget);
    await tester.tap(find.byKey(const Key('a-faire-action-depot-3')));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    final puts = requetes.where((r) => r.method == 'PUT').toList();
    expect(puts.map((r) => r.url.path.split('/api').last), ['/reservations/3/confirmer', '/reservations/3/assigner-transporteur']);
    expect(jsonDecode(puts.last.body)['transporteur_id'], 4);
  });
}
