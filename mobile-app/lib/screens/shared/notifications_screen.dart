import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../widgets/info_card.dart';

/// Liste des notifications sur l'application (lot 6) : les plus récentes
/// d'abord, non lues en évidence ; un appui marque comme lue.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map<String, dynamic>> _notifications = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    try {
      final res = await ApiService.getNotifications();
      if (mounted) setState(() { _notifications = ((res['notifications'] as List?) ?? []).cast<Map<String, dynamic>>(); _loading = false; _erreur = null; });
    } on ApiException catch (e) {
      if (mounted) setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _lire(Map<String, dynamic> n) async {
    if (n['read_at'] != null) return;
    setState(() => n['read_at'] = DateTime.now().toIso8601String());
    try { await ApiService.marquerNotificationLue(n['id'] as String); } on ApiException { /* réessayée au prochain chargement */ }
  }

  Future<void> _toutLire() async {
    try {
      await ApiService.toutMarquerLu();
      setState(() { for (final n in _notifications) { n['read_at'] ??= DateTime.now().toIso8601String(); } });
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  String _date(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (d == null) return '';
    String p(int n) => n.toString().padLeft(2, '0');
    return '${p(d.day)}/${p(d.month)} à ${p(d.hour)}:${p(d.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final nonLues = _notifications.where((n) => n['read_at'] == null).length;
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications'), actions: [
        if (nonLues > 0) TextButton(key: const Key('tout-lire'), onPressed: _toutLire,
            child: const Text('Tout lire', style: TextStyle(color: Colors.white))),
      ]),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : _notifications.isEmpty
                  ? const EmptyState(message: 'Aucune notification.', icon: Icons.notifications_none)
                  : RefreshIndicator(
                      onRefresh: _charger,
                      child: ListView.separated(
                        itemCount: _notifications.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (_, i) {
                          final n = _notifications[i];
                          final lue = n['read_at'] != null;
                          return ListTile(
                            key: Key('notification-${n['id']}'),
                            tileColor: lue ? null : const Color(0xFFF0FDF4),
                            leading: Icon(lue ? Icons.notifications_none : Icons.notifications_active, color: lue ? Colors.grey : Colors.green),
                            title: Text('${(n['data'] as Map?)?['message'] ?? ''}', style: TextStyle(fontWeight: lue ? FontWeight.normal : FontWeight.w600)),
                            subtitle: Text(_date(n['created_at'])),
                            onTap: () => _lire(n),
                          );
                        },
                      ),
                    ),
    );
  }
}
