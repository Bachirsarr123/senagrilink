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
import 'planifier_ventes_screen.dart';
import '../shared/tracabilite_screen.dart';
import '../shared/profil_screen.dart';

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

  Future<void> _modifierProduction(Production p) async {
    final typCtl  = TextEditingController(text: p.typeCulture ?? '');
    final qteCtl  = TextEditingController(text: p.quantiteEstimee?.toStringAsFixed(0) ?? '');
    final qreCtl  = TextEditingController(text: p.quantiteReelle?.toStringAsFixed(0) ?? '');
    final dateCtl = TextEditingController(text: p.dateRecolte ?? '');

    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Modifier la production'),
        content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            controller: typCtl,
            decoration: const InputDecoration(labelText: 'Type de culture *'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: dateCtl,
            decoration: const InputDecoration(labelText: 'Date récolte (YYYY-MM-DD)'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: qteCtl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Quantité estimée (kg)', suffixText: 'kg'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: qreCtl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Quantité réelle (kg)', suffixText: 'kg'),
          ),
        ])),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          ElevatedButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sauvegarder')),
        ],
      ),
    );

    if (ok != true) return;
    if (typCtl.text.trim().isEmpty) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Type de culture requis.'), backgroundColor: AppTheme.rouge));
      return;
    }

    try {
      await ApiService.updateProduction(p.id, {
        'type_culture':     typCtl.text.trim(),
        'date_recolte':     dateCtl.text.trim().isEmpty ? null : dateCtl.text.trim(),
        'quantite_estimee': double.tryParse(qteCtl.text.trim()),
        'quantite_reelle':  double.tryParse(qreCtl.text.trim()),
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Production mise à jour.'), backgroundColor: AppTheme.vert));
        _charger();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
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
            icon: const Icon(Icons.storefront_outlined),
            tooltip: 'Planifier mes ventes',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const PlanifierVentesScreen())),
          ),
          IconButton(
            icon: const Icon(Icons.list_alt_outlined),
            tooltip: 'Demandes disponibles',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const CommandesDisponiblesScreen())),
          ),
          IconButton(
            icon: const Icon(Icons.qr_code_scanner_outlined),
            tooltip: 'Traçabilité',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const TracabiliteScreen())),
          ),
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert),
            onSelected: (v) {
              if (v == 'profil') Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfilScreen()));
              if (v == 'logout') { auth.logout(); Navigator.pushReplacementNamed(context, '/login'); }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'profil', child: ListTile(leading: Icon(Icons.person_outline), title: Text('Mon profil'), dense: true)),
              const PopupMenuItem(value: 'logout', child: ListTile(leading: Icon(Icons.logout), title: Text('Déconnexion'), dense: true)),
            ],
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
                        itemBuilder: (_, i) => _ProductionTile(
                          production: _productions[i],
                          onModifier: () => _modifierProduction(_productions[i]),
                        ),
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
  final Production production;
  final VoidCallback onModifier;
  const _ProductionTile({required this.production, required this.onModifier});

  @override
  Widget build(BuildContext context) {
    final p = production;
    final modifiable = p.statut == 'en_attente' || p.statut == 'disponible';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Text(p.typeCulture ?? '—',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
            StatusBadge(p.statut),
          ]),
          const SizedBox(height: 4),
          Text('🔍 ${p.codeTracabilite}',
              style: const TextStyle(fontSize: 11, color: AppTheme.vert, fontFamily: 'monospace')),
          if (p.dateRecolte != null)
            Text('📅 ${p.dateRecolte}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
          if (p.quantiteEstimee != null)
            Text('⚖️ ${p.quantiteEstimee!.toStringAsFixed(0)} kg estimés',
                style: const TextStyle(fontSize: 13)),
          if (p.quantiteReelle != null)
            Text('✅ ${p.quantiteReelle!.toStringAsFixed(0)} kg réels',
                style: const TextStyle(fontSize: 13)),
          if (modifiable) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton.icon(
                icon: const Icon(Icons.edit_outlined, size: 15),
                label: const Text('Modifier'),
                onPressed: onModifier,
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  textStyle: const TextStyle(fontSize: 13),
                ),
              ),
            ),
          ],
        ]),
      ),
    );
  }
}
