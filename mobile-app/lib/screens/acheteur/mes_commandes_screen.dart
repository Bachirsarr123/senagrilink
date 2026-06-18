import 'package:flutter/material.dart';
import '../../models/commande.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';

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
                      itemCount: _commandes.length,
                      itemBuilder: (_, i) {
                        final c = _commandes[i];
                        return Card(
                          color: c.alerteLivraison ? Colors.red.shade50 : null,
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                Text(c.numeroCommande, style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
                                StatusBadge(c.statut),
                              ]),
                              const SizedBox(height: 6),
                              Text(c.produit, style: const TextStyle(fontSize: 15)),
                              Text('${c.quantite.toStringAsFixed(0)} kg${c.prix != null ? ' — ${c.prix!.toStringAsFixed(0)} FCFA' : ''}',
                                  style: const TextStyle(color: Colors.grey, fontSize: 13)),
                              // Alerte livraison (règle métier 4)
                              if (c.alerteLivraison)
                                Container(
                                  margin: const EdgeInsets.only(top: 8),
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                  decoration: BoxDecoration(color: Colors.red.shade100, borderRadius: BorderRadius.circular(6)),
                                  child: const Row(children: [
                                    Icon(Icons.warning_amber_outlined, color: AppTheme.rouge, size: 15),
                                    SizedBox(width: 6),
                                    Text('Problème signalé sur la livraison', style: TextStyle(color: AppTheme.rouge, fontSize: 12, fontWeight: FontWeight.w600)),
                                  ]),
                                ),
                              // Statut livraison
                              if (c.livraison != null) ...[
                                const SizedBox(height: 6),
                                Row(children: [
                                  const Icon(Icons.local_shipping_outlined, size: 14, color: Colors.grey),
                                  const SizedBox(width: 4),
                                  StatusBadge(c.livraison!['statut'] ?? ''),
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
