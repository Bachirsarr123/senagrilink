import 'dart:convert';
import 'dart:io';

import 'package:agri_platform_mobile/models/livraison.dart';
import 'package:agri_platform_mobile/screens/acheteur/catalogue_screen.dart';
import 'package:agri_platform_mobile/screens/acheteur/mes_commandes_screen.dart';
import 'package:agri_platform_mobile/screens/acheteur/suivi_livraison_screen.dart';
import 'package:agri_platform_mobile/screens/auth/login_screen.dart';
import 'package:agri_platform_mobile/screens/auth/register_screen.dart';
import 'package:agri_platform_mobile/screens/gestionnaire/stocks_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/commandes_disponibles_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/depots_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/nouveau_depot_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/nouvelle_recolte_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/planifier_ventes_screen.dart';
import 'package:agri_platform_mobile/screens/producteur/productions_screen.dart';
import 'package:agri_platform_mobile/screens/shared/profil_screen.dart';
import 'package:agri_platform_mobile/screens/shared/tracabilite_screen.dart';
import 'package:agri_platform_mobile/screens/transporteur/detail_livraison_screen.dart';
import 'package:agri_platform_mobile/screens/transporteur/livraisons_screen.dart';
import 'package:agri_platform_mobile/screens/transporteur/reservations_screen.dart';
import 'package:agri_platform_mobile/services/api_service.dart';
import 'package:agri_platform_mobile/services/auth_provider.dart';
import 'package:agri_platform_mobile/widgets/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Mise en page de chaque écran sur petit téléphone, téléphone, grand téléphone,
/// paysage et tablette, avec un texte normal puis agrandi (réglage
/// d'accessibilité). Flutter signale tout débordement (« RenderFlex overflowed »)
/// comme une erreur : le test échoue si un écran déborde.
///
/// Les réponses d'API (test/fixtures/api_demo.json) viennent d'une base de
/// démonstration fictive, avec des libellés longs pour éprouver la mise en page.
void main() {
  final reponses = jsonDecode(File('test/fixtures/api_demo.json').readAsStringSync()) as Map<String, dynamic>;

  const formats = <String, Size>{
    'petit téléphone 320×568': Size(320, 568),
    'téléphone 360×800':       Size(360, 800),
    'téléphone 390×844':       Size(390, 844),
    'paysage 844×390':         Size(844, 390),
    'tablette 768×1024':       Size(768, 1024),
  };
  const echellesTexte = [1.0, 1.3];

  Map<String, dynamic> utilisateur(String role) => {
    'id': 1, 'nom': 'Ndiaye-Diop Fall', 'prenom': 'Aminata Mariama', 'email': 'producteur@demo.sn',
    'telephone': '770000001', 'adresse': 'Quartier Médina, rue 22 x 15, Thiès', 'role': role, 'statut': 'actif',
  };

  final livraisons = (reponses['/livraisons']['livraisons'] as List).cast<Map<String, dynamic>>();
  final livraison = Livraison.fromJson(livraisons[2]);
  final remise = Livraison.fromJson(livraisons[0]);

  // Écran → (rôle connecté, construction, texte attendu une fois les données chargées).
  // Le texte attendu garantit que l'écran a bien rendu son contenu réel, et non
  // un état d'erreur ou de chargement qui passerait le test sans rien mesurer.
  final ecrans = <String, (String, Widget Function(), String)>{
    'Connexion':                ('producteur', () => const LoginScreen(), 'Se connecter'),
    'Inscription':              ('producteur', () => const RegisterScreen(), 'Créer mon compte'),
    'Mes productions':          ('producteur', () => const ProductionsScreen(), 'LOT-2026-49RTEX'),
    'Nouvelle récolte':         ('producteur', () => const NouvelleRecolteScreen(), 'Enregistrer la récolte'),
    'Planifier mes ventes':     ('producteur', () => const PlanifierVentesScreen(), 'LOT-2026-H83OSV'),
    'Commandes disponibles':    ('producteur', () => const CommandesDisponiblesScreen(), 'Acheteur : Moussa Ba'),
    'Mes dépôts':               ('producteur', () => const DepotsScreen(), 'Je pars vers l\'entrepôt'),
    'Déposer une récolte':      ('producteur', () => const NouveauDepotScreen(), 'Envoyer la demande'),
    'Stocks':                   ('gestionnaire_entrepot', () => const StocksScreen(), '1340'),
    'Catalogue':                ('acheteur_gros', () => const CatalogueScreen(), 'oignon'),
    'Mes commandes':            ('acheteur_gros', () => const MesCommandesScreen(), 'J\'ai bien reçu ma commande'),
    'Suivi de livraison':       ('acheteur_gros', () => const SuiviLivraisonScreen(livraisonId: 1), 'En cours'),
    'Livraisons':               ('transporteur', () => const LivraisonsScreen(), 'LIV-20261019-ZLEH'),
    'Acheminements':            ('transporteur', () => const ReservationsScreen(), 'Accepter la mission'),
    'Détail de livraison':      ('transporteur', () => DetailLivraisonScreen(livraison: livraison), 'Accepter la mission'),
    'Remise par code':          ('transporteur', () => DetailLivraisonScreen(livraison: remise), 'Remettre avec le code'),
    'Profil':                   ('producteur', () => const ProfilScreen(afficherTuiles: false), 'Position de votre exploitation'),
    'Traçabilité':              ('acheteur_gros', () => const TracabiliteScreen(), 'Aminata Mariama Ndiaye-Diop Fall'),
  };

  // Police réelle (Roboto, fournie avec le SDK Flutter) plutôt que la police de
  // test où chaque caractère est un carré : les largeurs mesurées sont celles
  // d'un vrai téléphone Android. Sans le SDK à portée, la police de test reste,
  // plus large donc plus stricte.
  setUpAll(() async {
    final racine = Platform.environment['FLUTTER_ROOT'];
    final dossier = Directory('$racine/bin/cache/artifacts/material_fonts');
    if (racine == null || !dossier.existsSync()) return;
    final roboto = FontLoader('Roboto');
    for (final f in dossier.listSync().whereType<File>().where((f) => RegExp(r'Roboto-[A-Za-z]+\.ttf$').hasMatch(f.path))) {
      roboto.addFont(Future.value(ByteData.sublistView(f.readAsBytesSync())));
    }
    await roboto.load();
  });

  setUp(() {
    ApiService.client = MockClient((req) async {
      final chemin = req.url.path.replaceFirst(RegExp(r'^.*/api'), '');
      final corps = reponses[chemin];
      return http.Response(jsonEncode(corps ?? {'message': 'Non simulé'}), corps == null ? 404 : 200,
          headers: {'content-type': 'application/json; charset=utf-8'});
    });
  });

  for (final MapEntry(key: nomEcran, value: (role, construire, texteAttendu)) in ecrans.entries) {
    for (final MapEntry(key: nomFormat, value: taille) in formats.entries) {
      for (final echelle in echellesTexte) {
        testWidgets('$nomEcran — $nomFormat — texte ×$echelle : aucun débordement', (tester) async {
          SharedPreferences.setMockInitialValues({'agri_token': 'jeton-fictif', 'agri_user': jsonEncode(utilisateur(role))});
          tester.view.devicePixelRatio = 1;
          tester.view.physicalSize = taille;
          addTearDown(tester.view.reset);

          final auth = AuthProvider();
          await auth.init();

          await tester.pumpWidget(ChangeNotifierProvider.value(
            value: auth,
            child: MaterialApp(
              theme: AppTheme.theme,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(echelle)),
                child: child!,
              ),
              home: construire(),
            ),
          ));
          // Chargement des données simulées, puis rendu complet
          await tester.pump();
          await tester.pump(const Duration(milliseconds: 100));

          if (nomEcran == 'Traçabilité') {
            await tester.enterText(find.byType(TextField).first, 'LOT-2026-49RTEX');
            await tester.testTextInput.receiveAction(TextInputAction.search);
            await tester.pump();
            final bouton = find.byType(ElevatedButton);
            if (bouton.evaluate().isNotEmpty) await tester.tap(bouton.first);
            await tester.pump(const Duration(milliseconds: 100));
          }

          expect(tester.takeException(), isNull);
          expect(find.textContaining(texteAttendu, skipOffstage: false), findsWidgets,
              reason: '$nomEcran devrait afficher ses données ($texteAttendu)');
          // Faux messages d'erreur (le fond de carte, lui, est réellement indisponible en test)
          for (final faux in ['Position indisponible', 'Connexion au serveur perdue']) {
            expect(find.textContaining(faux, skipOffstage: false), findsNothing,
                reason: '$nomEcran ne devrait pas afficher « $faux »');
          }

          // Démonte l'écran : arrête les minuteurs (rafraîchissement du suivi GPS)
          await tester.pumpWidget(const SizedBox());
        });
      }
    }
  }
}
