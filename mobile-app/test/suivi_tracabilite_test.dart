import 'dart:convert';
import 'dart:io';

import 'package:agri_platform_mobile/screens/acheteur/suivi_livraison_screen.dart';
import 'package:agri_platform_mobile/screens/shared/tracabilite_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/utils/chronologie.dart';
import 'package:agri_platform_mobile/widgets/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Suivi de livraison et traçabilité : données réelles de la démo
/// (test/fixtures/api_demo.json), états du suivi simulés côté API.
void main() {
  final demo = jsonDecode(File('test/fixtures/api_demo.json').readAsStringSync()) as Map<String, dynamic>;
  final tracabilite = (demo['/tracabilite/LOT-2026-49RTEX'] as Map)['tracabilite'] as Map<String, dynamic>;

  group('construireChronologie', () {
    test('ordonne les étapes réelles et marque une seule étape actuelle', () {
      final etapes = construireChronologie(tracabilite);
      expect(etapes.first.type, 'recolte');
      final datees = etapes.where((e) => e.date != null).map((e) => DateTime.parse(e.date!)).toList();
      for (var i = 1; i < datees.length; i++) {
        expect(datees[i].isBefore(datees[i - 1]), isFalse, reason: 'ordre chronologique');
      }
      expect(etapes.where((e) => e.etat == EtatEtape.actuel || e.etat == EtatEtape.alerte), isNotEmpty);
      expect(etapes.where((e) => e.etat == EtatEtape.actuel).length, lessThanOrEqualTo(1));
    });

    test('aucune étape inventée : une étape par donnée enregistrée', () {
      final stocks = tracabilite['etapes_stockage'] as List;
      final commandes = [for (final s in stocks) ...(s['commandes'] as List)];
      final etapes = construireChronologie(tracabilite);
      expect(etapes.where((e) => e.type == 'entree').length, stocks.length);
      expect(etapes.where((e) => e.type == 'commande').length, commandes.length);
      expect(construireChronologie({'code_tracabilite': 'X', 'etape_production': null, 'etapes_stockage': []}), isEmpty);
    });

    test('livraison en cours : étape actuelle reliée à la carte', () {
      final etapes = construireChronologie({
        'etape_production': {'type_culture': 'riz', 'date_recolte': '2026-06-01'},
        'etapes_stockage': [{
          'entrepot': {'nom': 'Nord'}, 'date_entree': '2026-06-05',
          'commandes': [{'numero_commande': 'CMD-1', 'date_commande': '2026-06-10 09:00:00',
            'livraison': {'id': 9, 'statut': 'en_cours', 'date_depart': '2026-06-11 08:00:00'}}],
        }],
      });
      expect(etapes.map((e) => e.type), ['recolte', 'entree', 'commande', 'depart']);
      expect(etapes.last.etat, EtatEtape.actuel);
      expect(etapes.last.livraisonId, 9);
    });
  });

  group('SuiviLivraisonScreen', () {
    late List<String> requetes;
    final positionDemo = demo['/suivi/livraison/1'] as Map<String, dynamic>;

    Map<String, dynamic> position({Duration age = Duration.zero, Duration arrivee = const Duration(minutes: 35)}) {
      final maintenant = DateTime.now().toUtc();
      return {
        ...positionDemo,
        'genere_le': maintenant.toIso8601String(),
        'arrivee_estimee_le': maintenant.add(arrivee).toIso8601String(),
        'position': {...positionDemo['position'] as Map, 'timestamp': maintenant.subtract(age).toIso8601String()},
      };
    }

    /// Hors trajet : le serveur répond sans position (Q11).
    Map<String, dynamic> horsTrajet(String statut, {Map<String, dynamic> details = const {}}) => {
      ...positionDemo, 'statut': statut, 'en_route': false, 'position': null, 'trace': [], 'trajet': null,
      'arrivee_estimee_le': null, 'details': {...positionDemo['details'] as Map, ...details},
    };

    void api(List<(int, Object)> reponses) {
      requetes = [];
      ApiService.client = MockClient((req) async {
        final chemin = req.url.path.replaceFirst(RegExp(r'^.*/api'), '');
        if (chemin == '/suivi/configuration') {
          return http.Response(jsonEncode(demo['/suivi/configuration']), 200, headers: {'content-type': 'application/json; charset=utf-8'});
        }
        if (chemin.startsWith('/tracabilite/')) {
          return http.Response(jsonEncode({'tracabilite': tracabilite}), 200, headers: {'content-type': 'application/json; charset=utf-8'});
        }
        final (code, corps) = reponses[requetes.length.clamp(0, reponses.length - 1)];
        requetes.add(chemin);
        if (corps is Exception) throw corps;
        return http.Response(jsonEncode(corps), code, headers: {'content-type': 'application/json; charset=utf-8'});
      });
    }

    Future<void> ouvrir(WidgetTester tester) async {
      SharedPreferences.setMockInitialValues({'agri_token': 'jeton', 'agri_user': jsonEncode({
        'id': 1, 'nom': 'Ba', 'prenom': 'Moussa', 'email': 'a@demo.sn', 'role': 'acheteur_gros', 'statut': 'actif'})});
      final auth = AuthProvider();
      await auth.init();
      tester.view.devicePixelRatio = 1;
      tester.view.physicalSize = const Size(390, 844);
      addTearDown(tester.view.reset);
      await tester.pumpWidget(ChangeNotifierProvider.value(
        value: auth,
        child: MaterialApp(theme: AppTheme.theme, home: const SuiviLivraisonScreen(livraisonId: 1)),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
    }

    Future<void> fermer(WidgetTester tester) => tester.pumpWidget(const SizedBox());

    testWidgets('premier chargement : carte, position en direct, aucun faux message d\'erreur', (tester) async {
      api([(200, position())]);
      await ouvrir(tester);
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.textContaining('En direct'), findsOneWidget);
      expect(find.textContaining('Position indisponible'), findsNothing);
      // Heure d'arrivée estimée par la route, trace et trajet dessinés
      expect(find.byKey(const Key('arrivee-estimee')), findsOneWidget);
      expect(find.textContaining('dans 3'), findsOneWidget);
      expect(find.textContaining('Estimation par la route'), findsOneWidget);
      expect(find.textContaining('Ousmane Sarr-Diallo'), findsOneWidget);
      final traits = tester.widget<PolylineLayer>(find.byType(PolylineLayer)).polylines;
      expect(traits, hasLength(2));
      await fermer(tester);
    });

    testWidgets('une position ancienne n\'est jamais présentée « en direct »', (tester) async {
      api([(200, position(age: const Duration(minutes: 12)))]);
      await ouvrir(tester);
      expect(find.textContaining('Dernière position connue · il y a 12 min'), findsOneWidget);
      expect(find.textContaining('En direct'), findsNothing);
      await fermer(tester);
    });

    testWidgets('livraison terminée : plus de carte ni de position, suivi arrêté', (tester) async {
      api([(200, horsTrajet('livree', details: {'arrive_le': '2026-10-19T15:00:00Z'}))]);
      await ouvrir(tester);
      expect(find.byType(FlutterMap), findsNothing);
      expect(find.text('Trajet terminé'), findsOneWidget);
      expect(find.text('Livré le'), findsOneWidget);
      await tester.pump(const Duration(minutes: 2));
      expect(requetes, hasLength(1));
      await fermer(tester);
    });

    testWidgets('livraison pas encore partie : le suivi reprend seul au départ', (tester) async {
      api([(200, horsTrajet('chargee')), (200, position())]);
      await ouvrir(tester);
      expect(find.text('Pas encore parti'), findsOneWidget);
      await tester.pump(delaiAttente);
      await tester.pump(const Duration(milliseconds: 50));
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.textContaining('En direct'), findsOneWidget);
      await fermer(tester);
    });

    testWidgets('coupure réseau : la dernière position reste, signalée comme ancienne', (tester) async {
      api([(200, position()), (0, const SocketException('hors ligne'))]);
      await ouvrir(tester);
      await tester.pump(delaiSuivi);
      await tester.pump(const Duration(milliseconds: 50));
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.textContaining('Connexion au serveur perdue'), findsOneWidget);
      expect(find.textContaining('Dernière position connue'), findsOneWidget);
      await fermer(tester);
    });

    testWidgets('accès refusé : message dédié, aucune nouvelle requête', (tester) async {
      api([(403, {'message': 'Accès non autorisé à cette position.'})]);
      await ouvrir(tester);
      expect(find.text('Accès refusé'), findsOneWidget);
      await tester.pump(const Duration(minutes: 2));
      expect(requetes, hasLength(1));
      await fermer(tester);
    });

    testWidgets('de la carte à la chronologie du lot', (tester) async {
      api([(200, position())]);
      await ouvrir(tester);
      await tester.ensureVisible(find.text('Voir la traçabilité du lot'));
      await tester.tap(find.text('Voir la traçabilité du lot'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(TracabiliteScreen), findsOneWidget);
      expect(find.text('Chronologie'), findsOneWidget);
      expect(find.text('Récolte'), findsOneWidget);
      await fermer(tester);
    });
  });
}
