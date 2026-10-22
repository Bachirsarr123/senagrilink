import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../utils/paiement.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/info_card.dart';

/// « Mon argent » (lot 12), pour le producteur, l'entrepôt et le transporteur,
/// même contenu que la page du site : mis de côté, versé, à recevoir en main
/// propre, détail de chaque vente, et le numéro où recevoir l'argent.
class ArgentScreen extends StatefulWidget {
  const ArgentScreen({super.key});
  @override
  State<ArgentScreen> createState() => _ArgentScreenState();
}

class _ArgentScreenState extends State<ArgentScreen> {
  Map<String, dynamic>? _d;
  String? _erreur;
  String _moyen = 'wave';
  final _numero = TextEditingController();

  @override
  void initState() { super.initState(); _charger(); }

  @override
  void dispose() { _numero.dispose(); super.dispose(); }

  Future<void> _charger() async {
    try {
      final r = (await ApiService.getArgent() as Map).cast<String, dynamic>();
      if (!mounted) return;
      setState(() { _d = r; _erreur = null; _moyen = r['moyen_paiement'] as String? ?? 'wave'; _numero.text = r['numero_paiement'] as String? ?? ''; });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _enregistrer() async {
    final messager = ScaffoldMessenger.of(context);
    try {
      await ApiService.updateProfile({'moyen_paiement': _moyen, 'numero_paiement': _numero.text.trim().isEmpty ? null : _numero.text.trim()});
      messager.showSnackBar(const SnackBar(content: Text('Numéro de réception enregistré.'), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
  }

  Widget _total(String titre, Object? montant, Color couleur, String aide) => Expanded(child: Card(child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(titre, style: const TextStyle(color: Colors.grey)),
          FittedBox(child: Text(fcfa(nombre(montant) ?? 0), style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: couleur))),
          Text(aide, style: const TextStyle(fontSize: 12, color: Colors.grey)),
        ]),
      )));

  @override
  Widget build(BuildContext context) {
    final t = (_d?['totaux'] as Map?)?.cast<String, dynamic>() ?? const {};
    final lignes = ((_d?['versements'] as List?) ?? const []).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: const Text('Mon argent')),
      body: _d == null
          ? (_erreur != null ? ErrorState(message: _erreur!, onRetry: _charger) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _charger, child: ListView(padding: const EdgeInsets.all(8), children: [
              Row(children: [
                _total('Mis de côté', t['mis_de_cote'], AppTheme.orange, 'versé à la réception'),
                _total('Déjà versé', t['verse'], AppTheme.vertFonce, 'sur votre ${moyensPaiement[_moyen] ?? 'compte'}'),
              ]),
              if ((nombre(t['a_regler']) ?? 0) > 0)
                Card(color: const Color(0xFFFEF3C7), child: ListTile(leading: const Icon(Icons.payments_outlined),
                    title: Text('${fcfa(nombre(t['a_regler'])!)} à recevoir en main propre'), subtitle: const Text('Payé en espèces au transporteur'))),
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('Recevoir mon argent sur', style: TextStyle(fontWeight: FontWeight.bold)),
                Row(children: [
                  DropdownButton<String>(value: _moyen, items: const [
                    DropdownMenuItem(value: 'wave', child: Text('Wave')), DropdownMenuItem(value: 'orange_money', child: Text('Orange Money')),
                  ], onChanged: (v) => setState(() => _moyen = v ?? 'wave')),
                  const SizedBox(width: 12),
                  Expanded(child: TextField(key: const Key('numero-paiement'), controller: _numero, keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(hintText: '77 123 45 67'))),
                ]),
                const SizedBox(height: 8),
                ElevatedButton(key: const Key('enregistrer-numero'), onPressed: _enregistrer, child: const Text('Enregistrer')),
              ]))),
              const Padding(padding: EdgeInsets.fromLTRB(8, 12, 8, 4), child: Text('Détail', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
              if (lignes.isEmpty) const Padding(padding: EdgeInsets.all(8), child: Text('Aucun mouvement pour l\'instant.', style: TextStyle(color: Colors.grey))),
              for (final v in lignes) Card(child: ListTile(
                title: Text('${beneficiaires[v['beneficiaire']] ?? v['beneficiaire']} — ${(v['commande'] as Map?)?['numero_commande'] ?? ''}'),
                subtitle: Text([
                  '${(v['commande'] as Map?)?['produit'] ?? ''}, ${kg(nombre((v['commande'] as Map?)?['quantite']) ?? 0)}',
                  if (v['beneficiaire'] == 'producteur')
                    '${fcfa(nombre((v['commande'] as Map?)?['prix']) ?? 0)} − entrepôt ${fcfa(nombre((v['commande'] as Map?)?['frais_stockage']) ?? 0)} − commission ${fcfa(nombre((v['commande'] as Map?)?['commission']) ?? 0)}',
                  etatsVersement[v['statut']] ?? '${v['statut']}',
                ].join('\n')),
                isThreeLine: true,
                trailing: Text(fcfa(nombre(v['montant']) ?? 0), style: const TextStyle(fontWeight: FontWeight.bold)),
              )),
            ])),
    );
  }
}
