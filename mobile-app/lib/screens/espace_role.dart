import 'package:flutter/material.dart';
import 'acheteur/catalogue_screen.dart';
import 'acheteur/mes_commandes_screen.dart';
import 'gestionnaire/commandes_entrepot_screen.dart';
import 'gestionnaire/depots_entrepot_screen.dart';
import 'gestionnaire/incidents_screen.dart';
import 'gestionnaire/stocks_screen.dart';
import 'producteur/depots_screen.dart';
import 'producteur/productions_screen.dart';
import 'shared/a_faire_screen.dart';
import 'transporteur/livraisons_screen.dart';
import 'transporteur/reservations_screen.dart';

typedef Onglet = ({String libelle, IconData icone, Widget Function() ecran, List<String> categories});

/// Espace de chaque rôle (lot 5) : « À faire » d'abord, puis les écrans du
/// rôle en onglets. Le gestionnaire a désormais toutes ses actions sur l'appli.
class EspaceRole extends StatefulWidget {
  final String role;
  const EspaceRole({super.key, required this.role});

  static List<Onglet> onglets(String role) => switch (role) {
    'producteur' => [
      (libelle: 'Récoltes', icone: Icons.grass, ecran: () => const ProductionsScreen(), categories: const []),
      (libelle: 'Dépôts', icone: Icons.warehouse_outlined, ecran: () => const DepotsScreen(), categories: const ['depot']),
    ],
    'transporteur' => [
      (libelle: 'Livraisons', icone: Icons.local_shipping_outlined, ecran: () => const LivraisonsScreen(), categories: const ['livraison']),
      (libelle: 'Acheminements', icone: Icons.agriculture, ecran: () => const ReservationsScreen(), categories: const ['depot']),
    ],
    'gestionnaire_entrepot' => [
      (libelle: 'Dépôts', icone: Icons.move_to_inbox_outlined, ecran: () => const DepotsEntrepotScreen(), categories: const ['depot']),
      (libelle: 'Commandes', icone: Icons.receipt_long_outlined, ecran: () => const CommandesEntrepotScreen(), categories: const ['commande']),
      (libelle: 'Incidents', icone: Icons.warning_amber_outlined, ecran: () => const IncidentsScreen(), categories: const ['incident']),
      (libelle: 'Stocks', icone: Icons.inventory_2_outlined, ecran: () => const StocksScreen(), categories: const ['stock']),
    ],
    'acheteur_gros' => [
      (libelle: 'Catalogue', icone: Icons.storefront_outlined, ecran: () => const CatalogueScreen(), categories: const []),
      (libelle: 'Commandes', icone: Icons.receipt_long_outlined, ecran: () => const MesCommandesScreen(), categories: const ['commande']),
    ],
    _ => const [],
  };

  @override
  State<EspaceRole> createState() => _EspaceRoleState();
}

class _EspaceRoleState extends State<EspaceRole> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final onglets = EspaceRole.onglets(widget.role);
    void ouvrir(String categorie) {
      final i = onglets.indexWhere((o) => o.categories.contains(categorie));
      if (i >= 0) setState(() => _index = i + 1);
    }

    return Scaffold(
      body: _index == 0 ? AFaireScreen(key: const ValueKey('a-faire'), ouvrirOnglet: ouvrir) : onglets[_index - 1].ecran(),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          const NavigationDestination(icon: Icon(Icons.checklist), label: 'À faire'),
          for (final o in onglets) NavigationDestination(icon: Icon(o.icone), label: o.libelle),
        ],
      ),
    );
  }
}
