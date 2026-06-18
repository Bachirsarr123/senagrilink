import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/production.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';
import 'nouvelle_recolte_screen.dart';
import 'commandes_disponibles_screen.dart';

class ProductionsScreen extends StatefulWidget {
  const ProductionsScreen({super.key});
  @override
  State<ProductionsScreen> createState() => _ProductionsScreenState();
}

class _ProductionsScreenState extends State<ProductionsScreen> {
  List<Production> _productions = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getProductions();
      setState(() {
        _productions = (res['productions'] as List).map((e) => Production.fromJson(e)).toList();
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
        title: Text('Bonjour, ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.list_alt_outlined),
            tooltip: 'Commandes disponibles',
            onPressed: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const CommandesDisponiblesScreen())),
          ),
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Déconnexion',
            onPressed: () async {
              await auth.logout();
              if (context.mounted) Navigator.pushReplacementNamed(context, '/login');
            },
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : _productions.isEmpty
                  ? const EmptyState(message: 'Aucune production enregistrée.', icon: Icons.agriculture)
                  : RefreshIndicator(
                      onRefresh: _charger,
                      child: ListView.builder(
                        itemCount: _productions.length,
                        itemBuilder: (_, i) => _ProductionTile(_productions[i]),
                      ),
                    ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppTheme.vert,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('Nouvelle récolte'),
        onPressed: () async {
          final created = await Navigator.push<bool>(
            context, MaterialPageRoute(builder: (_) => const NouvelleRecolteScreen()));
          if (created == true) _charger();
        },
      ),
    );
  }
}

class _ProductionTile extends StatelessWidget {
  final Production p;
  const _ProductionTile(this.p);

  @override
  Widget build(BuildContext context) => Card(
    child: ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      leading: const CircleAvatar(
        backgroundColor: AppTheme.vertClair,
        child: Icon(Icons.eco, color: AppTheme.vert),
      ),
      title: Text(p.typeCulture ?? '—', style: const TextStyle(fontWeight: FontWeight.bold)),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const SizedBox(height: 4),
        Text('🔍 ${p.codeTracabilite}', style: const TextStyle(fontSize: 11, color: AppTheme.vert)),
        if (p.dateRecolte != null) Text('📅 ${p.dateRecolte}', style: const TextStyle(fontSize: 12)),
        if (p.quantiteEstimee != null) Text('⚖️ ${p.quantiteEstimee!.toStringAsFixed(0)} kg estimés'),
      ]),
      trailing: StatusBadge(p.statut),
    ),
  );
}
