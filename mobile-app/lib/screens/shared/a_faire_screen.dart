import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/info_card.dart';
import '../../utils/action_directe.dart';
import '../conduite/conduite_screen.dart';
import 'fiche_commande_screen.dart';
import 'notifications_screen.dart';
import 'profil_screen.dart';

/// Page « À faire » (lot 5), premier onglet de chaque rôle : ce qui attend
/// une action de la personne connectée, calculé par le serveur.
class AFaireScreen extends StatefulWidget {
  /// Ouvre l'onglet du rôle qui convient (dépôts, commandes, incidents…).
  final void Function(String categorie)? ouvrirOnglet;
  const AFaireScreen({super.key, this.ouvrirOnglet});
  /// Délai pour annuler un appui par erreur sur un bouton d'action (lot 9).
  static Duration delaiAnnulation = const Duration(seconds: 4);
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
    'incident' => Icons.warning_amber_rounded, 'stock' => Icons.inventory_2_outlined, 'lieu' => Icons.location_on, _ => Icons.circle_outlined,
  };

  Future<void> _ouvrir(Map<String, dynamic> e) async {
    if (e['categorie'] == 'commande') {
      await Navigator.push(context, MaterialPageRoute(builder: (_) => FicheCommandeScreen(commandeId: e['sujet_id'] as int)));
    } else if (e['categorie'] == 'lieu') {
      // Entrepôt à placer sur la carte : le profil propose la position actuelle
      await Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfilScreen()));
    } else {
      widget.ouvrirOnglet?.call(e['categorie'] as String);
    }
    _charger();
  }

  /// Lot 9 : l'action principale se fait d'ici, d'un appui (quelques secondes pour annuler).
  /// Les étapes de transport ouvrent l'écran Conduite ; confier une mission va au transporteur proposé.
  static const _conduite = {'arriver_collecte', 'charger', 'partir', 'arriver'};
  int? _enCours;

  Future<void> _agirDirect(Map<String, dynamic> e) async {
    final action = e['action'] as String;
    final type = e['sujet_type'] as String;
    final id = e['sujet_id'] as int;
    final numero = e['numero'] as String? ?? '';
    if (_conduite.contains(action)) {
      await Navigator.push(context, MaterialPageRoute(builder: (_) => ConduiteScreen(type: type, id: id, numero: numero)));
      return _charger();
    }
    final propose = (e['transporteur_propose'] as Map?)?.cast<String, dynamic>();
    if (const ['assigner', 'organiser_livraison'].contains(action) && propose == null) return _ouvrir(e);

    final messager = ScaffoldMessenger.of(context);
    final libelle = _libelle(e);
    final annule = await messager.showSnackBar(SnackBar(
      content: Text('« $libelle » — $numero'), duration: AFaireScreen.delaiAnnulation,
      persist: false, // se ferme seul : l'action part si personne n'annule
      action: SnackBarAction(label: 'ANNULER', onPressed: () {}),
    )).closed;
    if (annule == SnackBarClosedReason.action || !mounted) return;
    setState(() => _enCours = id);
    try {
      final message = await executerActionDirecte(type, id, action, transporteurId: propose?['id'] as int?, statut: e['statut'] as String?);
      messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
      if (action == 'accepter_mission' && mounted) {
        await Navigator.push(context, MaterialPageRoute(builder: (_) => ConduiteScreen(type: type, id: id, numero: numero)));
      }
    } on ApiException catch (err) {
      messager.showSnackBar(SnackBar(content: Text(err.message), backgroundColor: AppTheme.rouge));
    }
    if (mounted) setState(() => _enCours = null);
    _charger();
  }

  String _libelle(Map<String, dynamic> e) {
    final action = e['action'] as String;
    if (_conduite.contains(action)) return 'Conduire';
    final propose = (e['transporteur_propose'] as Map?)?.cast<String, dynamic>();
    final nom = propose == null ? null
        : '${propose['nom']}${propose['distance_km'] != null ? ' (à ${nombreLisible(propose['distance_km'] as num)} km)' : ''}';
    return libelleActionDirecte(action, sujetType: e['sujet_type'] as String? ?? '', transporteur: nom);
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
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [ListTile(
                        key: Key('a-faire-${e['categorie']}-${e['sujet_id']}'),
                        leading: Icon(_icone(e['categorie'] as String? ?? ''), color: e['urgence'] == 'haute' ? AppTheme.rouge : AppTheme.vert),
                        title: Text(e['etape'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: Text('${e['titre'] ?? ''}\n${e['numero'] ?? ''}${_depuis(e['depuis'])}${e['en_retard'] == true ? ' · en retard' : ''}'),
                        isThreeLine: true,
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _ouvrir(e),
                      ),
                      if (e['action'] != null) Padding(
                        padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                        child: SizedBox(height: 50, child: ElevatedButton(
                          key: Key('a-faire-action-${e['categorie']}-${e['sujet_id']}'),
                          onPressed: _enCours == e['sujet_id'] ? null : () => _agirDirect(e),
                          child: Text(_enCours == e['sujet_id'] ? 'Envoi…' : _libelle(e), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                        )),
                      ),
                      ]),
                    ),
                  ]),
                ),
    );
  }
}
