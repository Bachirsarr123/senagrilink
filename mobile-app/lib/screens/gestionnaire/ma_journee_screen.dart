import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../utils/action_directe.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/info_card.dart';
import '../shared/a_faire_screen.dart';
import '../shared/fiche_commande_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/profil_screen.dart';
import 'agriculteurs_screen.dart';
import '../paiement/argent_screen.dart';

/// « Ma journée » du gestionnaire (lot 11), même rôle que la page du site : en
/// tête ce qui demande son attention, puis ce qui arrive, ce qui est à préparer
/// et ce qui part. Le pilote automatique fait le reste ; ses réglages sont ici.
class MaJourneeScreen extends StatefulWidget {
  final void Function(String categorie)? ouvrirOnglet;
  const MaJourneeScreen({super.key, this.ouvrirOnglet});
  @override
  State<MaJourneeScreen> createState() => _MaJourneeScreenState();
}

class _MaJourneeScreenState extends State<MaJourneeScreen> {
  Map<String, dynamic>? _j;
  String? _erreur;
  int? _enCours;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    try {
      final res = (await ApiService.getJournee() as Map).cast<String, dynamic>();
      if (mounted) setState(() { _j = res; _erreur = null; });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  List<Map<String, dynamic>> _liste(String cle) => ((_j?[cle] as List?) ?? const []).cast<Map>().map((e) => e.cast<String, dynamic>()).toList();

  Future<void> _regler(String regle, Object valeur) async {
    final messager = ScaffoldMessenger.of(context);
    setState(() => (_j!['pilote'] as Map)[regle] = valeur);
    try {
      await ApiService.updateProfile({regle: valeur});
      messager.showSnackBar(const SnackBar(content: Text('Réglage du pilote automatique enregistré.'), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
      _charger();
    }
  }

  Future<void> _prete(Map<String, dynamic> c) async {
    final messager = ScaffoldMessenger.of(context);
    setState(() => _enCours = c['id'] as int);
    try {
      final message = await executerActionDirecte('commande', c['id'] as int, c['action'] as String, statut: c['statut'] as String?);
      messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    if (mounted) setState(() => _enCours = null);
    _charger();
  }

  String _heure(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    return d == null ? '' : ' · arrivée vers ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  Widget _section(String titre, IconData icone, List<Map<String, dynamic>> elements, String vide, Widget Function(Map<String, dynamic>) ligne) => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [Icon(icone, color: AppTheme.vert), const SizedBox(width: 8),
              Expanded(child: Text('$titre (${elements.length})', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)))]),
            const SizedBox(height: 6),
            if (elements.isEmpty) Text(vide, style: const TextStyle(color: Colors.grey)),
            for (final e in elements) ...[const Divider(height: 14), ligne(e)],
          ]),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    final pilote = (_j?['pilote'] as Map?)?.cast<String, dynamic>();
    final attention = _liste('attention');
    return Scaffold(
      appBar: AppBar(
        title: const Text('Ma journée'),
        actions: [
          IconButton(key: const Key('mon-argent'), tooltip: 'Mon argent', icon: const Icon(Icons.account_balance_wallet_outlined),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ArgentScreen()))),
          IconButton(key: const Key('agriculteurs'), tooltip: 'Agriculteurs', icon: const Icon(Icons.person_search),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AgriculteursScreen()))),
          IconButton(tooltip: 'Notifications', icon: const Icon(Icons.notifications_outlined),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen()))),
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
      body: _j == null
          ? (_erreur != null ? ErrorState(message: _erreur!, onRetry: _charger) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _charger,
              child: ListView(padding: const EdgeInsets.all(8), children: [
                // À traiter : seulement ce que le pilote ne peut pas faire
                Card(
                  color: attention.isEmpty ? AppTheme.vertClair : const Color(0xFFFEF3C7),
                  child: ListTile(
                    key: const Key('a-traiter'),
                    leading: Icon(attention.isEmpty ? Icons.check_circle : Icons.warning_amber_rounded,
                        color: attention.isEmpty ? AppTheme.vertFonce : AppTheme.orange),
                    title: Text(attention.isEmpty ? 'Rien ne demande votre attention' : '${attention.length} chose(s) à traiter',
                        style: const TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text(attention.isEmpty ? 'Le pilote automatique s\'occupe du reste.' : attention.map((e) => e['etape']).take(3).join(' · ')),
                    trailing: attention.isEmpty ? null : const Icon(Icons.chevron_right),
                    onTap: attention.isEmpty ? null : () async {
                      await Navigator.push(context, MaterialPageRoute(builder: (_) => Scaffold(body: AFaireScreen(ouvrirOnglet: (c) {
                        Navigator.pop(context);
                        widget.ouvrirOnglet?.call(c);
                      }))));
                      _charger();
                    },
                  ),
                ),
                _section('Arrivent', Icons.agriculture, _liste('arrivent'), 'Aucune arrivée prévue.', (a) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${(a['producteur'] as Map?)?['nom'] ?? ''} — ${a['produit']}, ${kg(nombre(a['quantite']) ?? 0)}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${a['etape'] ?? ''}${_heure(a['arrivee_estimee_le'])}', style: const TextStyle(color: Colors.grey)),
                  Text(a['livre_lui_meme'] == true ? 'Livré par le producteur'
                      : (a['transporteur'] != null ? '🚚 ${(a['transporteur'] as Map)['nom']}' : 'Transporteur en cours de recherche'), style: const TextStyle(color: Colors.grey)),
                  if (a['a_receptionner'] == true) Padding(padding: const EdgeInsets.only(top: 6), child: ElevatedButton(
                    onPressed: () => widget.ouvrirOnglet?.call('depot'), child: const Text('Réceptionner'))),
                ])),
                _section('À préparer', Icons.inventory_2_outlined, _liste('a_preparer'), 'Rien à préparer.', (c) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text('${c['produit']}, ${kg(nombre(c['quantite']) ?? 0)} pour ${(c['acheteur'] as Map?)?['nom'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${c['numero']}', style: const TextStyle(color: Colors.grey)),
                  const SizedBox(height: 6),
                  SizedBox(height: 50, child: ElevatedButton(
                    key: Key('prete-${c['id']}'),
                    onPressed: _enCours == c['id'] ? null : () => _prete(c),
                    child: Text(_enCours == c['id'] ? 'Envoi…' : libelleActionDirecte(c['action'] as String, sujetType: 'commande'),
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  )),
                ])),
                _section('Partent', Icons.local_shipping_outlined, _liste('partent'), 'Aucun départ en cours.', (l) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${l['produit']}, ${kg(nombre(l['quantite']) ?? 0)} pour ${(l['acheteur'] as Map?)?['nom'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text('${l['etape'] ?? ''}${_heure(l['arrivee_estimee_le'])}', style: const TextStyle(color: Colors.grey)),
                  Text(l['transporteur'] != null ? '🚚 ${(l['transporteur'] as Map)['nom']}${l['mission_acceptee'] == true ? '' : ' (doit accepter)'}' : 'Aucun transporteur',
                      style: TextStyle(color: l['sans_transporteur'] == true ? AppTheme.rouge : Colors.grey)),
                  if (l['sans_transporteur'] == true) TextButton(
                    onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => FicheCommandeScreen(commandeId: l['commande_id'] as int))).then((_) => _charger()),
                    child: const Text('Choisir un transporteur')),
                ])),
                if (pilote != null) Card(child: Column(children: [
                  const ListTile(leading: Icon(Icons.smart_toy_outlined), title: Text('Pilote automatique', style: TextStyle(fontWeight: FontWeight.bold)),
                      subtitle: Text('Ce que le système fait pour vous')),
                  SwitchListTile(key: const Key('pilote-depots'), title: const Text('Accepter les dépôts'), value: pilote['auto_accepter_depots'] == true,
                      onChanged: (v) => _regler('auto_accepter_depots', v)),
                  SwitchListTile(key: const Key('pilote-commandes'), title: const Text('Confirmer les commandes'), value: pilote['auto_confirmer_commandes'] == true,
                      onChanged: (v) => _regler('auto_confirmer_commandes', v)),
                  SwitchListTile(key: const Key('pilote-transporteurs'), title: const Text('Trouver les transporteurs'), value: pilote['auto_affecter_transporteurs'] == true,
                      onChanged: (v) => _regler('auto_affecter_transporteurs', v)),
                  ListTile(title: const Text('Délai de réponse d\'un transporteur'), trailing: DropdownButton<int>(
                    value: [5, 10, 15, 30].contains(pilote['delai_reponse_min']) ? pilote['delai_reponse_min'] as int : 10,
                    items: [for (final m in [5, 10, 15, 30]) DropdownMenuItem(value: m, child: Text('$m min'))],
                    onChanged: (m) { if (m != null) _regler('delai_reponse_min', m); },
                  )),
                ])),
              ]),
            ),
    );
  }
}
