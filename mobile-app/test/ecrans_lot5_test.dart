import 'dart:convert';

import 'package:agri_platform_mobile/screens/espace_role.dart';
import 'package:agri_platform_mobile/screens/gestionnaire/commandes_entrepot_screen.dart';
import 'package:agri_platform_mobile/screens/gestionnaire/depots_entrepot_screen.dart';
import 'package:agri_platform_mobile/screens/gestionnaire/incidents_screen.dart';
import 'package:agri_platform_mobile/screens/shared/fiche_commande_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/widgets/scanner_qr.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lot 5 — écrans par rôle sur l'application : « À faire », gestionnaire
/// (commandes, dépôts avec QR code, incidents), fiche de commande.
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
      'id': 1, 'nom': 'Ndiaye', 'prenom': 'Ibra', 'email': 'g@test.sn', 'role': role, 'statut': 'actif',
    })});
    ApiService.client = MockClient((req) async { requetes.add(req); return api(req); });
    final auth = AuthProvider();
    await auth.init();
    await tester.pumpWidget(ChangeNotifierProvider.value(value: auth, child: MaterialApp(home: ecran)));
    await tester.pumpAndSettle();
  }

  const transporteurs = {'transporteurs': [
    {'id': 5, 'type_vehicule': 'Camion', 'missions_actives': 0, 'utilisateur': {'prenom': 'Binta', 'nom': 'Sarr'}},
    {'id': 6, 'type_vehicule': 'Pick-up', 'missions_actives': 2, 'utilisateur': {'prenom': 'Alioune', 'nom': 'Fall'}},
  ]};

  testWidgets('gestionnaire : « À faire » mène à l\'onglet qui convient', (tester) async {
    await afficher(tester, 'gestionnaire_entrepot', const EspaceRole(role: 'gestionnaire_entrepot'), (req) async => switch (req.url.path) {
      final p when p.endsWith('/a-faire') => json({'elements': [
        {'categorie': 'incident', 'sujet_type': 'livraison', 'sujet_id': 2, 'numero': 'LIV-2', 'titre': 'Moteur', 'etape': 'Incident à décider : panne', 'urgence': 'haute', 'en_retard': false},
      ]}),
      final p when p.endsWith('/incidents') => json({'incidents': []}),
      _ => json({}),
    });
    expect(find.text('Incident à décider : panne'), findsOneWidget);
    expect(find.text('1 action(s) en attente'), findsOneWidget);
    expect(find.text('Dépôts'), findsOneWidget);
    expect(find.text('Stocks'), findsOneWidget);

    await tester.tap(find.byKey(const Key('a-faire-incident-2')));
    await tester.pumpAndSettle();
    expect(find.byType(IncidentsScreen), findsOneWidget);
  });

  testWidgets('gestionnaire : commande prête, livraison organisée avec le transporteur le moins occupé', (tester) async {
    var commande = {'id': 8, 'numero_commande': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'statut': 'prete', 'prix': 1500,
      'actions': ['organiser_livraison', 'annuler_commande'], 'prochaine_etape': {'libelle': 'Livraison à organiser'}, 'incidents': [],
      'acheteur': {'utilisateur': {'prenom': 'Fatou', 'nom': 'Sow', 'telephone': '771234567'}}};
    await afficher(tester, 'gestionnaire_entrepot', const CommandesEntrepotScreen(), (req) async {
      if (req.url.path.endsWith('/transporteurs')) return json(transporteurs);
      if (req.method == 'GET') return json({'commandes': [commande]});
      commande = {...commande, 'actions': ['changer_transporteur', 'annuler_commande'], 'prochaine_etape': {'libelle': 'Mission à accepter par le transporteur'}};
      return json({'message': 'Livraison organisée. Le transporteur doit accepter la mission.'}, 201);
    });

    expect(find.textContaining('Fatou Sow · 771234567'), findsOneWidget);
    await tester.tap(find.byKey(const Key('commande-8-organiser_livraison')));
    await tester.pumpAndSettle();
    expect(find.text('Camion · 0 mission(s) en cours'), findsOneWidget);
    await tester.tap(find.byKey(const Key('transporteur-5')));
    await tester.pumpAndSettle();

    final envoi = requetes.firstWhere((r) => r.method == 'POST');
    expect(envoi.url.path, endsWith('/livraisons'));
    expect(jsonDecode(envoi.body), {'commande_id': 8, 'transporteur_id': 5});
    expect(envoi.headers['Idempotency-Key'], isNotNull);
    expect(find.text('Changer de transporteur'), findsOneWidget);
  });

  testWidgets('gestionnaire : réception d\'un dépôt avec le QR code du lot ; un écart exige un motif', (tester) async {
    ScannerQr.remplacement = (_) async => 'LOT-2026-AAAAAA';
    addTearDown(() => ScannerQr.remplacement = null);
    final depot = {'id': 7, 'numero_reservation': 'RES-7', 'produit': 'mil', 'quantite_reservee': 40, 'statut': 'arrivee_entrepot',
      'mode_transport': 'transporteur', 'actions': ['receptionner', 'refuser_reception'],
      'producteur': {'utilisateur': {'prenom': 'Awa', 'nom': 'Diallo', 'telephone': '770000000'}}, 'entrepot': {'nom_entrepot': 'Nord'},
      'prochaine_etape': {'libelle': 'Réception à contrôler'}};
    await afficher(tester, 'gestionnaire_entrepot', const DepotsEntrepotScreen(), (req) async =>
        req.method == 'GET' ? json({'reservations': [depot]}) : json({'message': 'Marchandise enregistrée.'}, 201));

    expect(find.textContaining('Awa Diallo · 770000000'), findsOneWidget);
    await tester.tap(find.byKey(const Key('depot-7-receptionner')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('scanner-lot')));
    await tester.pumpAndSettle();
    expect(find.text('LOT-2026-AAAAAA'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('quantite-recue')), '38');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Enregistrer la réception'));
    await tester.pumpAndSettle();
    expect(find.text('Un écart avec la quantité prévue exige un motif.'), findsOneWidget);
    expect(requetes.where((r) => r.method == 'PUT'), isEmpty);

    await tester.enterText(find.byKey(const Key('motif-ecart')), 'Deux sacs humides');
    await tester.tap(find.text('Enregistrer la réception'));
    await tester.pumpAndSettle();
    final envoi = requetes.firstWhere((r) => r.method == 'PUT');
    expect(envoi.url.path, endsWith('/reservations/7/valider'));
    expect(jsonDecode(envoi.body), {'code_lot': 'LOT-2026-AAAAAA', 'quantite_reelle': 38.0, 'etat_marchandise': 'bon', 'motif': 'Deux sacs humides'});
  });

  testWidgets('gestionnaire : décision d\'incident avec motif obligatoire', (tester) async {
    var incidents = [{'id': 5, 'type': 'demande_annulation', 'statut': 'ouvert', 'description': 'Plus besoin', 'sujet': {'numero': 'CMD-8'},
      'declarant': {'prenom': 'Fatou', 'nom': 'Sow'}, 'decisions': ['accepter_annulation', 'refuser_annulation']}];
    await afficher(tester, 'gestionnaire_entrepot', const IncidentsScreen(), (req) async {
      if (req.method == 'GET') return json({'incidents': incidents});
      incidents = [{...incidents.first, 'statut': 'resolu', 'decision': 'refuser_annulation', 'motif_decision': 'Déjà chargée', 'decisions': <String>[]}];
      return json({'message': 'ok'});
    });

    await tester.tap(find.byKey(const Key('incident-5')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('decision-refuser_annulation')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Enregistrer la décision'));
    await tester.pumpAndSettle();
    expect(find.text('Indiquez le motif.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('motif-decision')), 'Déjà chargée');
    await tester.tap(find.text('Enregistrer la décision'));
    await tester.pumpAndSettle();

    expect(jsonDecode(requetes.firstWhere((r) => r.method == 'PUT').body), {'decision': 'refuser_annulation', 'motif': 'Déjà chargée'});
    expect(find.text('Aucun incident à décider.'), findsOneWidget);
    expect(find.textContaining('Refuser l\'annulation (Déjà chargée)'), findsOneWidget);
  });

  testWidgets('fiche de commande : étapes avec auteur, action directe', (tester) async {
    var statut = 'confirmee';
    await afficher(tester, 'gestionnaire_entrepot', const FicheCommandeScreen(commandeId: 8), (req) async {
      if (req.method == 'GET') {
        return json({'role': 'gestionnaire', 'commande': {'id': 8, 'numero_commande': 'CMD-8', 'produit': 'riz', 'quantite': 10, 'statut': statut, 'prix': 1500},
          'actions': statut == 'confirmee' ? ['preparer', 'annuler_commande'] : [], 'prochaine_etape': {'libelle': 'Préparation à lancer'},
          'lot': {'code_tracabilite': 'LOT-2026-AAAAAA', 'entrepot': 'Nord'}, 'acheteur': {'nom': 'Fatou Sow', 'telephone': '771234567'},
          'livraison': null, 'incidents': [], 'etapes': [{'date': '2026-10-21T08:00:00Z', 'libelle': 'Création', 'auteur': 'Fatou Sow', 'sujet': 'commande'}]});
      }
      statut = 'en_preparation';
      return json({'message': 'Préparation lancée.'});
    });

    expect(find.text('Création'), findsOneWidget);
    expect(find.textContaining('Fatou Sow · 771234567'), findsOneWidget);
    await tester.tap(find.byKey(const Key('fiche-preparer')));
    await tester.pumpAndSettle();
    expect(requetes.firstWhere((r) => r.method == 'PUT').url.path, endsWith('/commandes/8/preparer'));
    expect(find.byKey(const Key('fiche-preparer')), findsNothing);
  });
}
