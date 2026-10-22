import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/carte_depot.dart' show confirmerAction, demanderMotifAction;
import '../../widgets/info_card.dart';
import '../../widgets/status_badge.dart';
import '../acheteur/suivi_livraison_screen.dart';
import 'tracabilite_screen.dart';

/// Fiche d'une commande (lot 5), commune à tous les acteurs : état, étape
/// attendue, étapes datées avec leur auteur, livraison, incidents. Le serveur
/// retire ce qui ne regarde pas le lecteur.
class FicheCommandeScreen extends StatefulWidget {
  final int commandeId;
  const FicheCommandeScreen({super.key, required this.commandeId});
  @override
  State<FicheCommandeScreen> createState() => _FicheCommandeScreenState();
}

class _FicheCommandeScreenState extends State<FicheCommandeScreen> {
  static const _directes = {
    'confirmer': 'Confirmer', 'preparer': 'Lancer la préparation', 'marquer_prete': 'Commande prête',
    'confirmer_reception': 'J\'ai bien reçu ma commande', 'demander_annulation': 'Demander l\'annulation',
  };

  Map<String, dynamic>? _f;
  String? _erreur;
  bool _envoi = false;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    try {
      final f = await ApiService.getFicheCommande(widget.commandeId) as Map<String, dynamic>;
      if (mounted) setState(() { _f = f; _erreur = null; });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.statusCode == 403 ? 'Vous n\'avez pas accès à cette commande.' : e.message);
    }
  }

  Future<void> _agir(String a) async {
    final c = _f!['commande'] as Map<String, dynamic>;
    final id = c['id'] as int;
    final messager = ScaffoldMessenger.of(context);
    Future<dynamic> Function() appel;
    switch (a) {
      case 'confirmer':
        if (!await confirmerAction(context, 'Confirmer la commande ${c['numero_commande']} ?')) return;
        appel = () => ApiService.confirmerCommande(id);
      case 'preparer':
        appel = () => ApiService.preparerCommande(id);
      case 'marquer_prete':
        appel = () => ApiService.marquerCommandePrete(id);
      case 'confirmer_reception':
        if (!await confirmerAction(context, 'Confirmer que vous avez bien reçu ${kg(nombre(c['quantite']) ?? 0)} de ${c['produit']} ?')) return;
        appel = () => ApiService.confirmerReception(id);
      default:
        final motif = await demanderMotifAction(context, 'Demander l\'annulation ?', 'L\'entrepôt décide.', obligatoire: false);
        if (motif == null) return;
        appel = () => ApiService.demanderAnnulation(id, motif: motif);
    }
    setState(() => _envoi = true);
    try {
      final res = await appel();
      messager.showSnackBar(SnackBar(content: Text((res is Map ? res['message'] : null) ?? 'Enregistré.'), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    if (!mounted) return;
    setState(() => _envoi = false);
    _charger();
  }

  Widget _ligne(String libelle, String? valeur) => valeur == null || valeur.isEmpty ? const SizedBox.shrink() : Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(width: 110, child: Text(libelle, style: const TextStyle(color: Colors.grey, fontSize: 13))),
      Expanded(child: Text(valeur)),
    ]),
  );

  String _date(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (d == null) return '';
    String p(int n) => n.toString().padLeft(2, '0');
    return '${p(d.day)}/${p(d.month)} ${p(d.hour)}:${p(d.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final f = _f;
    return Scaffold(
      appBar: AppBar(title: Text(f == null ? 'Commande' : (f['commande']['numero_commande'] as String? ?? 'Commande'))),
      body: _erreur != null
          ? ErrorState(message: _erreur!, onRetry: _charger)
          : f == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _charger,
                  child: ListView(padding: const EdgeInsets.all(12), children: _contenu(f)),
                ),
    );
  }

  List<Widget> _contenu(Map<String, dynamic> f) {
    final c = f['commande'] as Map<String, dynamic>;
    final l = f['livraison'] as Map<String, dynamic>?;
    final lot = f['lot'] as Map<String, dynamic>? ?? const {};
    final acheteur = f['acheteur'] as Map<String, dynamic>?;
    final actions = ((f['actions'] as List?) ?? const []).cast<String>().where(_directes.containsKey).toList();
    return [
      Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text('${c['produit']} — ${kg(nombre(c['quantite']) ?? 0)}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold))),
          StatusBadge(c['statut'] ?? ''),
        ]),
        if ((f['prochaine_etape'] as Map?)?['libelle'] != null)
          Padding(padding: const EdgeInsets.only(top: 6), child: Text('➡ ${f['prochaine_etape']['libelle']}', style: const TextStyle(fontWeight: FontWeight.w600))),
        if (f['code_reception'] != null) Container(
          margin: const EdgeInsets.only(top: 8),
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(6)),
          child: Text('Code de réception : ${f['code_reception']}', style: const TextStyle(fontWeight: FontWeight.bold)),
        ),
        const Divider(),
        if (c['prix'] != null) _ligne('Montant', fcfa(nombre(c['prix']) ?? 0)),
        _ligne('Entrepôt', lot['entrepot'] as String?),
        if (acheteur != null && f['role'] != 'acheteur')
          _ligne('Acheteur', '${acheteur['nom'] ?? ''}${acheteur['telephone'] != null ? ' · ${acheteur['telephone']}' : ''}'),
        if (lot['code_tracabilite'] != null) Align(alignment: Alignment.centerLeft, child: TextButton.icon(
          icon: const Icon(Icons.qr_code_2),
          label: Text('Lot ${lot['code_tracabilite']}'),
          onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => TracabiliteScreen(codeInitial: lot['code_tracabilite'] as String))),
        )),
        if (actions.isNotEmpty) Wrap(spacing: 8, runSpacing: 8, children: [
          for (final a in actions)
            a == 'demander_annulation'
                ? OutlinedButton(onPressed: _envoi ? null : () => _agir(a), child: Text(_directes[a]!))
                : ElevatedButton(key: Key('fiche-$a'), onPressed: _envoi ? null : () => _agir(a), child: Text(_directes[a]!)),
        ]),
      ]))),
      if (l != null) Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text('Livraison ${l['numero_livraison']}', style: const TextStyle(fontWeight: FontWeight.bold))),
          StatusBadge(l['statut'] ?? ''),
        ]),
        if (l['transporteur'] != null)
          _ligne('Transporteur', '${l['transporteur']['nom']}${l['transporteur']['telephone'] != null ? ' · ${l['transporteur']['telephone']}' : ''}'),
        _ligne('Destination', l['destination'] as String?),
        if (l['suivi_disponible'] == true) ElevatedButton.icon(
          icon: const Icon(Icons.map_outlined),
          label: const Text('Suivre sur la carte'),
          onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SuiviLivraisonScreen(livraisonId: l['id'] as int))),
        ),
      ]))),
      for (final i in ((f['incidents'] as List?) ?? const []).cast<Map<String, dynamic>>())
        Card(color: i['statut'] == 'ouvert' ? Colors.red.shade50 : null, child: ListTile(
          leading: const Icon(Icons.warning_amber_outlined),
          title: Text('${i['libelle']} — ${i['statut'] == 'ouvert' ? 'en attente de décision' : 'traité'}'),
          subtitle: Text('${i['description'] ?? ''}${i['decision'] != null ? '\nDécision : ${i['decision']}' : ''}'),
        )),
      const Padding(padding: EdgeInsets.fromLTRB(4, 12, 4, 4), child: Text('Étapes', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
      for (final e in ((f['etapes'] as List?) ?? const []).cast<Map<String, dynamic>>())
        ListTile(
          dense: true,
          leading: Text(_date(e['date']), style: const TextStyle(fontSize: 12, color: Colors.grey)),
          title: Text('${e['libelle']}${e['sujet'] == 'livraison' ? ' (livraison)' : ''}'),
          subtitle: Text('${e['auteur'] ?? ''}${e['motif'] != null ? ' · Motif : ${e['motif']}' : ''}'),
        ),
    ];
  }
}
