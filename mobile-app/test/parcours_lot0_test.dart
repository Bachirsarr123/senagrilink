import 'dart:convert';

import 'package:agri_platform_mobile/main.dart';
import 'package:agri_platform_mobile/screens/auth/login_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Parcours corrigés au lot 0, sur l'application réelle (AgriPlatformApp) avec
/// une API simulée renvoyant les nombres au format PostgreSQL (chaînes).
void main() {
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
    brancherFinSession(auth);
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: const AgriPlatformApp()));
    await tester.pumpAndSettle();
    await ouvrirOnglet(tester, const {'acheteur_gros': 'Catalogue', 'producteur': 'Récoltes'}[utilisateur['role']] ?? '');
  }

  Future<void> arreter(WidgetTester tester) => tester.pumpWidget(const SizedBox());

  http.Response json(Object corps, [int statut = 200]) =>
      http.Response.bytes(utf8.encode(jsonEncode(corps)), statut, headers: {'content-type': 'application/json'});

  const acheteur = {'id': 4, 'nom': 'Ba', 'prenom': 'Moussa', 'email': 'a@test.sn', 'role': 'acheteur_gros', 'statut': 'actif'};
  final catalogue = {'catalogue': [
    {'stock_id': 5, 'produit': 'riz', 'quantite': '40', 'quantite_disponible': '40', 'prix_unitaire': '150', 'entrepot': {'nom_entrepot': 'Thiès-Nord', 'localisation': 'Thiès'},
     'production': {'code_tracabilite': 'LOT-2026-AAAAAA'}},
  ]};

  testWidgets('acheteur : commande passée depuis le catalogue mobile (P-09)', (tester) async {
    await demarrer(tester, acheteur, (req) async => req.method == 'POST'
        ? json({'commande': {'id': 9, 'numero_commande': 'CMD-20261020-ABCD'}}, 201)
        : json(catalogue));

    expect(find.text('40 kg dispo.'), findsOneWidget); // quantité « 40 » (chaîne PostgreSQL) affichée
    expect(find.text('150 FCFA / kg'), findsOneWidget); // prix fixé par le producteur
    await tester.tap(find.text('Commander'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('commande-quantite')), '50');
    await tester.tap(find.text('Confirmer la commande'));
    await tester.pumpAndSettle();
    expect(find.text('Au plus 40 kg disponibles.'), findsOneWidget);
    expect(requetes.where((r) => r.method == 'POST'), isEmpty);

    await tester.enterText(find.byKey(const Key('commande-quantite')), '12,5');
    await tester.pump();
    expect(find.text('Total : 1875 FCFA'), findsOneWidget);
    expect(find.byKey(const Key('commande-prix')), findsNothing); // l'acheteur ne saisit pas de prix
    await tester.tap(find.text('Confirmer la commande'));
    await tester.pumpAndSettle();

    final post = requetes.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/commandes'));
    expect(jsonDecode(post.body), {'stock_id': 5, 'quantite': 12.5});
    expect(post.headers['Idempotency-Key'], isNotNull);
    expect(find.textContaining('CMD-20261020-ABCD'), findsOneWidget);
    await arreter(tester);
  });

  testWidgets('acheteur : nouvel essai après une erreur serveur avec la même clé, sans doublon', (tester) async {
    var essais = 0;
    await demarrer(tester, acheteur, (req) async {
      if (req.method != 'POST') return json(catalogue);
      essais++;
      return essais == 1 ? http.Response('<html>502</html>', 502) : json({'commande': {'id': 9, 'numero_commande': 'CMD-X'}}, 201);
    });

    await tester.tap(find.text('Commander'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('commande-quantite')), '5');
    await tester.tap(find.text('Confirmer la commande'));
    await tester.pumpAndSettle();
    expect(find.text('Erreur serveur (502)'), findsOneWidget); // erreur affichée, formulaire conservé

    await tester.tap(find.text('Confirmer la commande'));
    await tester.pumpAndSettle();

    final cles = requetes.where((r) => r.method == 'POST').map((r) => r.headers['Idempotency-Key']).toList();
    expect(cles, hasLength(2));
    expect(cles.toSet(), hasLength(1));
    expect(find.textContaining('CMD-X'), findsOneWidget);
    await arreter(tester);
  });

  testWidgets('administrateur : se déconnecter depuis l\'écran mobile (P-19)', (tester) async {
    await demarrer(tester, {'id': 1, 'nom': 'Admin', 'prenom': 'A', 'email': 'ad@test.sn', 'role': 'administrateur', 'statut': 'actif'},
        (req) async => json({'message': 'ok'}));

    expect(find.textContaining('site web'), findsOneWidget);
    await tester.tap(find.text('Se déconnecter'));
    await tester.pumpAndSettle();

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(requetes.any((r) => r.url.path.endsWith('/logout')), isTrue);
    expect(await ApiService.getToken(), isNull);
    await arreter(tester);
  });

  testWidgets('producteur : productions au format PostgreSQL affichées, pas de chargement infini (P-07)', (tester) async {
    await demarrer(tester, {'id': 2, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'p@test.sn', 'role': 'producteur', 'statut': 'actif'},
        (req) async => json({'productions': [
              {'id': 1, 'code_tracabilite': 'LOT-2026-BBBBBB', 'type_culture': 'oignon', 'statut': 'en_attente',
               'superficie': '1.5', 'quantite_estimee': '120', 'quantite_reelle': null, 'date_recolte': '2026-06-01'},
            ]}));

    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.textContaining('LOT-2026-BBBBBB'), findsOneWidget);
    await arreter(tester);
  });

  testWidgets('producteur : serveur injoignable, message et bouton Réessayer au lieu du chargement infini (P-20)', (tester) async {
    await demarrer(tester, {'id': 2, 'nom': 'Diallo', 'prenom': 'Awa', 'email': 'p@test.sn', 'role': 'producteur', 'statut': 'actif'},
        (req) async => throw http.ClientException('hors ligne'));

    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.textContaining('Serveur injoignable'), findsOneWidget);
    await arreter(tester);
  });
}

/// Lot 5 : l'application s'ouvre sur « À faire » ; on passe à l'onglet voulu.
Future<void> ouvrirOnglet(WidgetTester tester, String libelle) async {
  final onglet = find.descendant(of: find.byType(NavigationBar), matching: find.text(libelle));
  if (onglet.evaluate().isEmpty) return;
  await tester.tap(onglet);
  await tester.pumpAndSettle();
}
