import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/stock.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';

class StocksScreen extends StatefulWidget {
  const StocksScreen({super.key});
  @override
  State<StocksScreen> createState() => _StocksScreenState();
}

class _StocksScreenState extends State<StocksScreen> with SingleTickerProviderStateMixin {
  List<Stock> _stocks = [];
  List<Stock> _alertes = [];
  bool _loading = true;
  String? _erreur;
  late TabController _tabs;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _charger();
  }

  @override
  void dispose() { _tabs.dispose(); super.dispose(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final resStocks  = await ApiService.getStocks();
      final resAlertes = await ApiService.getAlertes();
      setState(() {
        _stocks  = (resStocks['stocks']   as List).map((e) => Stock.fromJson(e)).toList();
        _alertes = (resAlertes['alertes'] as List).map((e) => Stock.fromJson(e)).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    final totalQte = _stocks.fold(0.0, (s, e) => s + e.quantite);

    return Scaffold(
      appBar: AppBar(
        title: Text('Stocks — ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(icon: const Icon(Icons.logout), onPressed: () async {
            await auth.logout();
            if (context.mounted) Navigator.pushReplacementNamed(context, '/login');
          }),
        ],
        bottom: TabBar(
          controller: _tabs,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: [
            const Tab(text: 'Tous les stocks'),
            Tab(text: 'Alertes (${_alertes.length})'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : Column(children: [
                  // Résumé
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    child: Row(children: [
                      Expanded(child: InfoCard(icon: Icons.inventory_2_outlined, label: 'Lignes', value: '${_stocks.length}')),
                      Expanded(child: InfoCard(icon: Icons.scale_outlined, label: 'Total (kg)', value: totalQte.toStringAsFixed(0))),
                      Expanded(child: InfoCard(icon: Icons.warning_amber_outlined, label: 'Alertes', value: '${_alertes.length}', color: _alertes.isNotEmpty ? AppTheme.rouge : AppTheme.vert)),
                    ]),
                  ),
                  Expanded(
                    child: TabBarView(
                      controller: _tabs,
                      children: [
                        _buildStocksList(_stocks),
                        _buildAlertsList(),
                      ],
                    ),
                  ),
                ]),
    );
  }

  Widget _buildStocksList(List<Stock> stocks) {
    if (stocks.isEmpty) return const EmptyState(message: 'Aucun stock enregistré.');
    return RefreshIndicator(
      onRefresh: _charger,
      child: ListView.builder(
        itemCount: stocks.length,
        itemBuilder: (_, i) {
          final s = stocks[i];
          return Card(
            color: s.enAlerte ? Colors.amber.shade50 : null,
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: s.enAlerte ? Colors.amber.shade100 : AppTheme.vertClair,
                child: Icon(Icons.inventory_2_outlined, color: s.enAlerte ? AppTheme.orange : AppTheme.vert),
              ),
              title: Row(children: [
                Expanded(child: Text(s.produit, style: const TextStyle(fontWeight: FontWeight.bold))),
                if (s.enAlerte) const Icon(Icons.warning_amber, color: AppTheme.orange, size: 16),
              ]),
              subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${s.quantite.toStringAsFixed(0)} kg disponibles'),
                if (s.seuilAlerte != null) Text('Seuil : ${s.seuilAlerte!.toStringAsFixed(0)} kg', style: const TextStyle(fontSize: 11)),
                if (s.dateEntree != null) Text('Entré le : ${s.dateEntree}', style: const TextStyle(fontSize: 11)),
              ]),
              trailing: StatusBadge(s.statut),
            ),
          );
        },
      ),
    );
  }

  Widget _buildAlertsList() {
    if (_alertes.isEmpty) return const EmptyState(message: '✅ Tous les stocks sont au-dessus de leur seuil.', icon: Icons.check_circle_outline);
    return ListView.builder(
      itemCount: _alertes.length,
      itemBuilder: (_, i) {
        final s = _alertes[i];
        final deficit = (s.seuilAlerte ?? 0) - s.quantite;
        return Card(
          color: Colors.red.shade50,
          child: ListTile(
            leading: const CircleAvatar(backgroundColor: Color(0xFFFEE2E2), child: Icon(Icons.warning_amber, color: AppTheme.rouge)),
            title: Text(s.produit, style: const TextStyle(fontWeight: FontWeight.bold)),
            subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Quantité actuelle : ${s.quantite.toStringAsFixed(0)} kg', style: const TextStyle(color: AppTheme.rouge)),
              Text('Seuil : ${s.seuilAlerte!.toStringAsFixed(0)} kg — Déficit : ${deficit.toStringAsFixed(0)} kg'),
            ]),
          ),
        );
      },
    );
  }
}
