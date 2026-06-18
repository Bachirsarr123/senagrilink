import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/auth_provider.dart';
import 'producteur/productions_screen.dart';
import 'transporteur/livraisons_screen.dart';
import 'gestionnaire/stocks_screen.dart';
import 'acheteur/catalogue_screen.dart';

/// Redirige vers le bon écran d'accueil selon le rôle de l'utilisateur connecté.
class HomeRouter extends StatelessWidget {
  const HomeRouter({super.key});

  @override
  Widget build(BuildContext context) {
    final role = context.read<AuthProvider>().role;
    return switch (role) {
      'producteur'            => const ProductionsScreen(),
      'transporteur'          => const LivraisonsScreen(),
      'gestionnaire_entrepot' => const StocksScreen(),
      'acheteur_gros'         => const CatalogueScreen(),
      _                       => const _AccessRefused(),
    };
  }
}

class _AccessRefused extends StatelessWidget {
  const _AccessRefused();
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Accès refusé')),
    body: const Center(child: Text('Ce rôle n\'a pas d\'interface mobile.')),
  );
}
