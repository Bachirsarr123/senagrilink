import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/info_card.dart';
import 'fiche_commande_screen.dart';
import 'notifications_screen.dart';
import 'profil_screen.dart';

/// Page « À faire » (lot 5), premier onglet de chaque rôle : ce qui attend
/// une action de la personne connectée, calculé par le serveur.
class AFaireScreen extends StatefulWidget {
  /// Ouvre l'onglet du rôle qui convient (dépôts, commandes, incidents…).
  final void Function(String categorie)? ouvrirOnglet;
  const AFaireScreen({super.key, this.ouvrirOnglet});
  @override
  State<AFaireScreen> createState() => _AFaireScreenState();
}

class _AFaireScreenState extends State<AFaireScreen> {
  List<Map<String, dynamic>> _elements = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = _elements.isEmpty; _erreur = null; });
    try {
      final res = await ApiService.getAFaire();
      if (mounted) setState(() { _elements = ((res['elements'] as List?) ?? []).cast<Map<String, dynamic>>(); _loading = false; });
    } on ApiException catch (e) {
      if (mounted) setState(() { _erreur = e.message; _loading = false; });
    }
  }

  IconData _icone(String c) => switch (c) {
    'depot' => Icons.agriculture, 'commande' => Icons.receipt_long, 'livraison' => Icons.local_shipping,
    'incident' => Icons.warning_amber_rounded, 'stock' => Icons.inventory_2_outlined, _ => Icons.circle_outlined,
  };

  Future<void> _ouvrir(Map<String, dynamic> e) async {
    if (e['categorie'] == 'commande') {
      await Navigator.push(context, MaterialPageRoute(builder: (_) => FicheCommandeScreen(commandeId: e['sujet_id'] as int)));
    } else {
      widget.ouvrirOnglet?.call(e['categorie'] as String);
    }
    _charger();
  }

  String _depuis(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (d == null) return '';
    String p(int n) => n.toString().padLeft(2, '0');
    return ' · depuis le ${p(d.day)}/${p(d.month)} à ${p(d.hour)}:${p(d.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    final retard = _elements.where((e) => e['en_retard'] == true).length;
    return Scaffold(
      appBar: AppBar(
        title: Text('À faire — ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(
            key: const Key('ouvrir-notifications'),
            tooltip: 'Notifications',
            icon: const Icon(Icons.notifications_outlined),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen())),
          ),
          PopupMenuButton<String>(
            onSelected: (v) {
              if (v == 'profil') Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfilScreen()));
              if (v == 'logout') { auth.logout(); Navigator.pushReplacementNamed(context, '/login'); }
            },
            itemBuilder: (_) => const [
              PopupMenuItem(value: 'profil', child: ListTile(leading: Icon(Icons.person_outline), title: Text('Mon profil'), dense: true)),
              PopupMenuItem(value: 'logout', child: ListTile(leading: Icon(Icons.logout), title: Text('Déconnexion'), dense: true)),
            ],
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : RefreshIndicator(
                  onRefresh: _charger,
                  child: ListView(padding: const EdgeInsets.all(8), children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
                      child: Text(_elements.isEmpty ? 'Tout est à jour. Les nouvelles demandes apparaîtront ici.'
                          : '${_elements.length} action(s) en attente${retard > 0 ? ' · $retard en retard' : ''}',
                          style: TextStyle(color: retard > 0 ? AppTheme.rouge : Colors.grey.shade700)),
                    ),
                    if (_elements.isEmpty) const EmptyState(message: 'Rien en attente de votre part.', icon: Icons.check_circle_outline),
                    for (final e in _elements) Card(
                      shape: e['urgence'] == 'haute'
                          ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: const BorderSide(color: AppTheme.rouge, width: 1.5))
                          : null,
                      child: ListTile(
                        key: Key('a-faire-${e['categorie']}-${e['sujet_id']}'),
                        leading: Icon(_icone(e['categorie'] as String? ?? ''), color: e['urgence'] == 'haute' ? AppTheme.rouge : AppTheme.vert),
                        title: Text(e['etape'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: Text('${e['titre'] ?? ''}\n${e['numero'] ?? ''}${_depuis(e['depuis'])}${e['en_retard'] == true ? ' · en retard' : ''}'),
                        isThreeLine: true,
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _ouvrir(e),
                      ),
                    ),
                  ]),
                ),
    );
  }
}
