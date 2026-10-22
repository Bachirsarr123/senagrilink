import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/api_service.dart';
import '../services/auth_provider.dart';
import '../services/suivi_gps_service.dart';
import '../services/notifications_push.dart';
import '../widgets/app_theme.dart';
import 'espace_role.dart';

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
    // Mission en route (application relancée, téléphone redémarré) : le partage de position reprend.
    final role = context.read<AuthProvider>().utilisateur?.role;
    if (role == 'transporteur' || role == 'producteur') SuiviGpsService.instance.reprendre();
    // Lot 6 : ce téléphone reçoit les notifications de ce compte (si Firebase est configuré).
    NotificationsPush.activer();
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
    // Lot 5 : chaque rôle arrive sur « À faire », ses écrans en onglets.
    return EspaceRole.onglets(role ?? '').isEmpty ? const _AccessRefused() : EspaceRole(role: role!);
  }
}

/// Rôle sans interface mobile (l'administrateur, selon le cahier des charges) :
/// l'utilisateur doit pouvoir se déconnecter, sinon sa session persistante le
/// ramenait sur cet écran à chaque lancement (P-19).
class _AccessRefused extends StatelessWidget {
  const _AccessRefused();

  Future<void> _deconnecter(BuildContext context) async {
    await context.read<AuthProvider>().logout();
    if (context.mounted) Navigator.pushReplacementNamed(context, '/login');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('SenAgriLink')),
    body: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.desktop_windows_outlined, size: 48, color: Colors.grey),
          const SizedBox(height: 12),
          const Text('Ce rôle n\'a pas d\'interface mobile.', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          const Text('L\'administration se fait depuis le site web SenAgriLink.', textAlign: TextAlign.center),
          const SizedBox(height: 20),
          ElevatedButton.icon(
            icon: const Icon(Icons.logout),
            label: const Text('Se déconnecter'),
            onPressed: () => _deconnecter(context),
          ),
        ]),
      ),
    ),
  );
}
