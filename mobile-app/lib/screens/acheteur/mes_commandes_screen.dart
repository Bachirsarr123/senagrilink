import 'package:flutter/material.dart';
import '../../models/commande.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';
import 'suivi_livraison_screen.dart';

class MesCommandesScreen extends StatefulWidget {
  const MesCommandesScreen({super.key});
  @override
  State<MesCommandesScreen> createState() => _MesCommandesScreenState();
}

class _MesCommandesScreenState extends State<MesCommandesScreen> {
  List<Commande> _commandes = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getHistoriqueCommandes();
      setState(() {
        _commandes = (res['commandes'] as List).map((e) => Commande.fromJson(e)).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _annuler(Commande c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Annuler la commande'),
        content: Text('Annuler la commande ${c.numeroCommande} ?\nCette action est irréversible.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Non')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.rouge),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Annuler la commande'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ApiService.annulerCommande(c.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Commande annulée.'), backgroundColor: AppTheme.rouge));
        _charger();
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
      }
    }
  }

  Future<void> _modifier(Commande c) async {
    final ctl = TextEditingController(text: c.quantite.toStringAsFixed(0));
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Modifier la commande'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('Commande : ${c.numeroCommande}',
              style: const TextStyle(fontSize: 13, color: Colors.grey)),
          const SizedBox(height: 12),
          TextField(
            controller: ctl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Nouvelle quantité (kg)',
              suffixText: 'kg',
            ),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Enregistrer'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final qte = double.tryParse(ctl.text.trim());
    if (qte == null || qte <= 0) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Quantité invalide.'), backgroundColor: AppTheme.rouge));
      return;
    }
    try {
      await ApiService.updateCommande(c.id, {'quantite': qte});
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Commande mise à jour.'), backgroundColor: AppTheme.vert));
        _charger();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Mes commandes')),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? ErrorState(message: _erreur!, onRetry: _charger)
            : _commandes.isEmpty
                ? const EmptyState(message: 'Aucune commande passée.', icon: Icons.receipt_long_outlined)
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(8),
                      itemCount: _commandes.length,
                      itemBuilder: (_, i) {
                        final c = _commandes[i];
                        return Card(
                          color: c.alerteLivraison ? Colors.red.shade50 : null,
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                Text(c.numeroCommande,
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
                                StatusBadge(c.statut),
                              ]),
                              const SizedBox(height: 6),
                              Text(c.produit, style: const TextStyle(fontSize: 15)),
                              Text('${c.quantite.toStringAsFixed(0)} kg'
                                  '${c.prix != null ? ' — ${c.prix!.toStringAsFixed(0)} FCFA' : ''}',
                                  style: const TextStyle(color: Colors.grey, fontSize: 13)),

                              if (c.alerteLivraison)
                                Container(
                                  margin: const EdgeInsets.only(top: 8),
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                  decoration: BoxDecoration(color: Colors.red.shade100, borderRadius: BorderRadius.circular(6)),
                                  child: const Row(children: [
                                    Icon(Icons.warning_amber_outlined, color: AppTheme.rouge, size: 15),
                                    SizedBox(width: 6),
                                    Text('Problème signalé sur la livraison',
                                        style: TextStyle(color: AppTheme.rouge, fontSize: 12, fontWeight: FontWeight.w600)),
                                  ]),
                                ),

                              if (c.livraison != null) ...[
                                const SizedBox(height: 6),
                                Row(children: [
                                  const Icon(Icons.local_shipping_outlined, size: 14, color: Colors.grey),
                                  const SizedBox(width: 4),
                                  StatusBadge(c.livraison!['statut'] ?? ''),
                                  if (c.livraison!['statut'] == 'en_cours') ...[
                                    const SizedBox(width: 8),
                                    TextButton.icon(
                                      icon: const Icon(Icons.map_outlined, size: 16),
                                      label: const Text('Suivre en direct'),
                                      style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 0)),
                                      onPressed: () => Navigator.push(context, MaterialPageRoute(
                                        builder: (_) => SuiviLivraisonScreen(livraisonId: c.livraison!['id']),
                                      )),
                                    ),
                                  ],
                                ]),
                              ],

                              // Actions pour commandes en_attente
                              if (c.statut == 'en_attente') ...[
                                const SizedBox(height: 10),
                                Row(children: [
                                  Expanded(child: OutlinedButton.icon(
                                    icon: const Icon(Icons.edit_outlined, size: 16),
                                    label: const Text('Modifier'),
                                    onPressed: () => _modifier(c),
                                  )),
                                  const SizedBox(width: 8),
                                  Expanded(child: OutlinedButton.icon(
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: AppTheme.rouge,
                                      side: const BorderSide(color: AppTheme.rouge),
                                    ),
                                    icon: const Icon(Icons.cancel_outlined, size: 16),
                                    label: const Text('Annuler'),
                                    onPressed: () => _annuler(c),
                                  )),
                                ]),
                              ],
                            ]),
                          ),
                        );
                      },
                    ),
                  ),
  );
}
