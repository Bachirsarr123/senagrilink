import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/info_card.dart';
import 'mes_commandes_screen.dart';

class CatalogueScreen extends StatefulWidget {
  const CatalogueScreen({super.key});
  @override
  State<CatalogueScreen> createState() => _CatalogueScreenState();
}

class _CatalogueScreenState extends State<CatalogueScreen> {
  List<Map<String, dynamic>> _produits = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getCatalogue();
      setState(() {
        _produits = List<Map<String, dynamic>>.from(res['catalogue']);
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    return Scaffold(
      appBar: AppBar(
        title: Text('Catalogue — ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.receipt_long_outlined),
            tooltip: 'Mes commandes',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const MesCommandesScreen())),
          ),
          IconButton(icon: const Icon(Icons.logout), onPressed: () async {
            await auth.logout();
            if (context.mounted) Navigator.pushReplacementNamed(context, '/login');
          }),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : _produits.isEmpty
                  ? const EmptyState(message: 'Aucun produit disponible.', icon: Icons.storefront_outlined)
                  : RefreshIndicator(
                      onRefresh: _charger,
                      child: ListView.builder(
                        padding: const EdgeInsets.only(bottom: 80),
                        itemCount: _produits.length,
                        itemBuilder: (_, i) {
                          final p = _produits[i];
                          final entrepot  = p['entrepot']  as Map?;
                          final production = p['production'] as Map?;
                          return Card(
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                  Text(p['produit'] ?? '—', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                    decoration: BoxDecoration(color: AppTheme.vertClair, borderRadius: BorderRadius.circular(12)),
                                    child: Text('${p['quantite']} kg', style: const TextStyle(color: AppTheme.vertFonce, fontWeight: FontWeight.bold)),
                                  ),
                                ]),
                                const SizedBox(height: 8),
                                if (entrepot != null) ...[
                                  Row(children: [
                                    const Icon(Icons.warehouse_outlined, size: 14, color: Colors.grey),
                                    const SizedBox(width: 4),
                                    Text(entrepot['nom_entrepot'] ?? '', style: const TextStyle(fontSize: 13)),
                                  ]),
                                  Row(children: [
                                    const Icon(Icons.location_on_outlined, size: 14, color: Colors.grey),
                                    const SizedBox(width: 4),
                                    Text(entrepot['localisation'] ?? '', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                  ]),
                                ],
                                if (production != null) ...[
                                  const SizedBox(height: 4),
                                  Row(children: [
                                    const Icon(Icons.qr_code, size: 14, color: AppTheme.vert),
                                    const SizedBox(width: 4),
                                    Text(production['code_tracabilite'] ?? '', style: const TextStyle(fontSize: 11, color: AppTheme.vert)),
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
}
