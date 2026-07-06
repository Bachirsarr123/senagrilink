import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/api_service.dart';
import '../services/auth_provider.dart';
import '../widgets/app_theme.dart';
import 'producteur/productions_screen.dart';
import 'transporteur/livraisons_screen.dart';
import 'gestionnaire/stocks_screen.dart';
import 'acheteur/catalogue_screen.dart';

const _intervallePollingNotifications = Duration(seconds: 10);

/// Redirige vers le bon écran d'accueil selon le rôle de l'utilisateur connecté.
/// Interroge aussi /notifications toutes les 10 secondes (polling — plus simple
/// que des WebSockets sur mobile) pour signaler les nouvelles notifications.
class HomeRouter extends StatefulWidget {
  const HomeRouter({super.key});

  @override
  State<HomeRouter> createState() => _HomeRouterState();
}

class _HomeRouterState extends State<HomeRouter> {
  Timer? _timer;
  int? _dernierNonLues;

  @override
  void initState() {
    super.initState();
    _verifierNotifications();
    _timer = Timer.periodic(_intervallePollingNotifications, (_) => _verifierNotifications());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _verifierNotifications() async {
    try {
      final res = await ApiService.getNotifications();
      final nonLues = res['non_lues'] as int? ?? 0;

      if (_dernierNonLues != null && nonLues > _dernierNonLues! && mounted) {
        final notifications = (res['notifications'] as List?) ?? [];
        String? derniere;
        if (notifications.isNotEmpty) {
          final data = notifications.first['data'];
          derniere = data is Map ? data['message'] as String? : null;
        }
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(derniere ?? 'Nouvelle notification.'),
          backgroundColor: AppTheme.vert,
        ));
      }
      _dernierNonLues = nonLues;
    } catch (_) {
      // Échec ponctuel : nouvelle tentative au prochain cycle.
    }
  }

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
