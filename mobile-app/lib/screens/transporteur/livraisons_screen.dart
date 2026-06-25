import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/livraison.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';
import 'detail_livraison_screen.dart';
import '../shared/tracabilite_screen.dart';
import '../shared/profil_screen.dart';

class LivraisonsScreen extends StatefulWidget {
  const LivraisonsScreen({super.key});
  @override
  State<LivraisonsScreen> createState() => _LivraisonsScreenState();
}

class _LivraisonsScreenState extends State<LivraisonsScreen> {
  List<Livraison> _livraisons = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getLivraisons();
      setState(() {
        _livraisons = (res['livraisons'] as List).map((e) => Livraison.fromJson(e)).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Color _couleurStatut(String s) => switch (s) {
    'en_cours'  => AppTheme.orange,
    'livree'    => Colors.green,
    'probleme'  => AppTheme.rouge,
    _           => Colors.grey,
  };

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    final enCours   = _livraisons.where((l) => l.statut == 'en_cours').length;
    final enAttente = _livraisons.where((l) => l.statut == 'en_attente').length;

    return Scaffold(
      appBar: AppBar(
        title: Text('Missions — ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.qr_code_scanner_outlined),
            tooltip: 'Traçabilité',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const TracabiliteScreen())),
          ),
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert),
            onSelected: (v) {
              if (v == 'profil') Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfilScreen()));
              if (v == 'logout') { auth.logout(); Navigator.pushReplacementNamed(context, '/login'); }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'profil', child: ListTile(leading: Icon(Icons.person_outline), title: Text('Mon profil'), dense: true)),
              const PopupMenuItem(value: 'logout', child: ListTile(leading: Icon(Icons.logout), title: Text('Déconnexion'), dense: true)),
            ],
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : Column(children: [
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    child: Row(children: [
                      Expanded(child: InfoCard(icon: Icons.local_shipping_outlined, label: 'En cours', value: '$enCours', color: AppTheme.orange)),
                      Expanded(child: InfoCard(icon: Icons.hourglass_empty, label: 'En attente', value: '$enAttente')),
                    ]),
                  ),
                  Expanded(
                    child: _livraisons.isEmpty
                        ? const EmptyState(message: 'Aucune mission assignée.', icon: Icons.local_shipping_outlined)
                        : RefreshIndicator(
                            onRefresh: _charger,
                            child: ListView.builder(
                              itemCount: _livraisons.length,
                              itemBuilder: (_, i) {
                                final l = _livraisons[i];
                                return Card(
                                  child: ListTile(
                                    leading: CircleAvatar(
                                      backgroundColor: _couleurStatut(l.statut).withAlpha(30),
                                      child: Icon(Icons.local_shipping, color: _couleurStatut(l.statut)),
                                    ),
                                    title: Text(l.numeroLivraison, style: const TextStyle(fontWeight: FontWeight.bold)),
                                    subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                      if (l.origine != null) Text('De : ${l.origine}', style: const TextStyle(fontSize: 12)),
                                      if (l.destination != null) Text('Vers : ${l.destination}', style: const TextStyle(fontSize: 12)),
                                      if (l.dateDepart != null) Text('Départ : ${l.dateDepart}', style: const TextStyle(fontSize: 11)),
                                    ]),
                                    trailing: StatusBadge(l.statut),
                                    onTap: () async {
                                      final updated = await Navigator.push<bool>(
                                        context, MaterialPageRoute(builder: (_) => DetailLivraisonScreen(livraison: l)));
                                      if (updated == true) _charger();
                                    },
                                  ),
                                );
                              },
                            ),
                          ),
                  ),
                ]),
    );
  }
}
