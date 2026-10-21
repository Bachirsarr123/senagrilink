import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/livraison.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/choix_transporteur.dart';
import '../../widgets/info_card.dart';
import '../../widgets/status_badge.dart';

/// Incidents à décider (lot 5) : le serveur indique les décisions possibles.
class IncidentsScreen extends StatefulWidget {
  const IncidentsScreen({super.key});
  @override
  State<IncidentsScreen> createState() => _IncidentsScreenState();
}

class _IncidentsScreenState extends State<IncidentsScreen> {
  static const _avecMotif = ['refuser_annulation', 'cloturer_litige', 'annuler_commande', 'annuler_depot', 'reprendre_marchandise'];
  static const _libelles = {
    'reprendre': 'La livraison reprend', 'changer_transporteur': 'Confier à un autre transporteur',
    'retour_entrepot': 'Retour à l\'entrepôt (nouvelle livraison ensuite)', 'annuler_commande': 'Annuler la commande (marchandise remise en stock)',
    'annuler_depot': 'Annuler le dépôt', 'accepter_annulation': 'Accepter l\'annulation', 'refuser_annulation': 'Refuser l\'annulation',
    'cloturer_litige': 'Clore le litige (arrangement trouvé)', 'reprendre_marchandise': 'Reprendre la marchandise et annuler',
  };

  List<Map<String, dynamic>> _incidents = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getIncidents();
      setState(() { _incidents = ((res['incidents'] as List?) ?? []).cast<Map<String, dynamic>>(); _loading = false; });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _decider(Map<String, dynamic> i) async {
    final messager = ScaffoldMessenger.of(context);
    final decisions = ((i['decisions'] as List?) ?? const []).cast<String>();
    final decision = await showModalBottomSheet<String>(context: context, builder: (c) => SafeArea(child: Column(mainAxisSize: MainAxisSize.min, children: [
      const Padding(padding: EdgeInsets.all(16), child: Text('Décision', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold))),
      for (final d in decisions) ListTile(key: Key('decision-$d'), title: Text(_libelles[d] ?? d), onTap: () => Navigator.pop(c, d)),
    ])));
    if (decision == null || !mounted) return;

    int? transporteur;
    if (decision == 'changer_transporteur') {
      transporteur = await choisirTransporteur(context, titre: 'Qui prend le relais ?');
      if (transporteur == null) return;
    }
    if (!mounted) return;
    final ctl = TextEditingController();
    final obligatoire = _avecMotif.contains(decision);
    String? erreur;
    final ok = await showDialog<bool>(context: context, builder: (c) => StatefulBuilder(builder: (c, setState) => AlertDialog(
      title: Text(_libelles[decision] ?? decision),
      content: TextField(key: const Key('motif-decision'), controller: ctl, maxLength: 500,
          decoration: InputDecoration(labelText: obligatoire ? 'Motif (obligatoire)' : 'Motif (facultatif)', errorText: erreur)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Renoncer')),
        ElevatedButton(onPressed: () {
          if (obligatoire && ctl.text.trim().isEmpty) { setState(() => erreur = 'Indiquez le motif.'); return; }
          Navigator.pop(c, true);
        }, child: const Text('Enregistrer la décision')),
      ],
    )));
    if (ok != true) return;
    try {
      await ApiService.resoudreIncident(i['id'] as int, {
        'decision': decision, if (ctl.text.trim().isNotEmpty) 'motif': ctl.text.trim(), 'transporteur_id': ?transporteur,
      });
      messager.showSnackBar(const SnackBar(content: Text('Décision enregistrée. Les personnes concernées ont été prévenues.'), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    _charger();
  }

  @override
  Widget build(BuildContext context) {
    final ouverts = _incidents.where((i) => i['statut'] == 'ouvert').toList();
    final traites = _incidents.where((i) => i['statut'] != 'ouvert').take(20).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Incidents')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : RefreshIndicator(
                  onRefresh: _charger,
                  child: ListView(padding: const EdgeInsets.all(8), children: [
                    if (ouverts.isEmpty) const EmptyState(message: 'Aucun incident à décider.', icon: Icons.check_circle_outline),
                    for (final i in ouverts) Card(child: ListTile(
                      key: Key('incident-${i['id']}'),
                      isThreeLine: true,
                      leading: const Icon(Icons.warning_amber_rounded, color: AppTheme.rouge),
                      title: Text('${libelleIncident(i['type'] ?? '')} — ${(i['sujet'] as Map?)?['numero'] ?? ''}'),
                      subtitle: Text('${i['description'] ?? ''}\nDéclaré par ${(i['declarant'] as Map?)?['prenom'] ?? ''} ${(i['declarant'] as Map?)?['nom'] ?? ''}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: ((i['decisions'] as List?) ?? const []).isEmpty ? null : () => _decider(i),
                    )),
                    if (traites.isNotEmpty) const Padding(padding: EdgeInsets.fromLTRB(8, 16, 8, 4), child: Text('Traités', style: TextStyle(fontWeight: FontWeight.bold))),
                    for (final i in traites) ListTile(
                      dense: true,
                      title: Text('${libelleIncident(i['type'] ?? '')} — ${(i['sujet'] as Map?)?['numero'] ?? ''}'),
                      subtitle: Text('${_libelles[i['decision']] ?? i['decision'] ?? ''}${i['motif_decision'] != null ? ' (${i['motif_decision']})' : ''}'),
                      trailing: StatusBadge(i['statut'] ?? ''),
                    ),
                  ]),
                ),
    );
  }
}
