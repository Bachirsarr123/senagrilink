import 'package:flutter/material.dart';
import '../../models/production.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../../utils/nombres.dart';

class PlanifierVentesScreen extends StatefulWidget {
  const PlanifierVentesScreen({super.key});
  @override
  State<PlanifierVentesScreen> createState() => _PlanifierVentesScreenState();
}

class _PlanifierVentesScreenState extends State<PlanifierVentesScreen>
    with SingleTickerProviderStateMixin {
  List<Production> _enAttente  = [];
  List<Production> _disponibles = [];
  List<dynamic>    _demandes   = [];
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
      final resP = await ApiService.getProductions();
      final resD = await ApiService.getCommandesDisponibles();
      final all  = (resP['productions'] as List).map((e) => Production.fromJson(e)).toList();
      setState(() {
        _enAttente   = all.where((p) => p.statut == 'en_attente').toList();
        _disponibles = all.where((p) => p.statut == 'disponible').toList();
        // Contrat de GET /api/commandes/disponibles : { commandes_disponibles: [...] }
        _demandes    = resD['commandes_disponibles'] as List;
        _loading     = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    } catch (_) {
      setState(() { _erreur = 'Réponse inattendue du serveur.'; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Planifier mes ventes'),
      bottom: TabBar(
        controller: _tabs,
        indicatorColor: Colors.white,
        labelColor: Colors.white,
        unselectedLabelColor: Colors.white70,
        tabs: [
          Tab(text: 'Mes récoltes (${_enAttente.length + _disponibles.length})'),
          Tab(text: 'Demandes (${_demandes.length})'),
        ],
      ),
    ),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
                Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
                TextButton(onPressed: _charger, child: const Text('Réessayer')),
              ]))
            : TabBarView(
                controller: _tabs,
                children: [
                  _buildProductions(),
                  _buildDemandes(),
                ],
              ),
  );

  Widget _buildProductions() {
    if (_enAttente.isEmpty && _disponibles.isEmpty) {
      return const Center(child: Text('Aucune production enregistrée.', style: TextStyle(color: Colors.grey)));
    }
    return RefreshIndicator(
      onRefresh: _charger,
      child: ListView(children: [
        if (_enAttente.isNotEmpty) ...[
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Text('En attente de réception en entrepôt',
                style: TextStyle(fontWeight: FontWeight.bold, color: Colors.grey)),
          ),
          ..._enAttente.map((p) => Card(
            margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Text(p.typeCulture ?? '—', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  StatusBadge(p.statut),
                ]),
                const SizedBox(height: 6),
                Text('Code : ${p.codeTracabilite}',
                    style: const TextStyle(fontSize: 11, color: AppTheme.vert, fontFamily: 'monospace')),
                if (p.dateRecolte != null)
                  Text('Récolte : ${p.dateRecolte}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                if (p.quantiteEstimee != null)
                  Text('${p.quantiteEstimee!.toStringAsFixed(0)} kg estimés',
                      style: const TextStyle(fontSize: 13)),
                const SizedBox(height: 10),
                // La mise en vente n'est pas une action du producteur : le lot devient
                // « disponible » quand l'entrepôt valide sa réception (règle serveur).
                const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.info_outline, size: 18, color: Colors.grey),
                  SizedBox(width: 6),
                  Expanded(child: Text(
                    'Pour vendre ce lot, réservez une place en entrepôt (espace web). '
                    'Il apparaîtra au catalogue dès que l\'entrepôt aura validé sa réception.',
                    style: TextStyle(fontSize: 12, color: Colors.grey),
                  )),
                ]),
              ]),
            ),
          )),
        ],
        if (_disponibles.isNotEmpty) ...[
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Text('Déjà disponibles à la vente',
                style: TextStyle(fontWeight: FontWeight.bold, color: Colors.grey)),
          ),
          ..._disponibles.map((p) => Card(
            color: Colors.green.shade50,
            margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            child: ListTile(
              leading: const CircleAvatar(backgroundColor: AppTheme.vertClair, child: Icon(Icons.check, color: AppTheme.vert)),
              title: Text(p.typeCulture ?? '—', style: const TextStyle(fontWeight: FontWeight.bold)),
              subtitle: Text('${p.quantiteEstimee?.toStringAsFixed(0) ?? '—'} kg — ${p.codeTracabilite}',
                  style: const TextStyle(fontSize: 12)),
              trailing: StatusBadge(p.statut),
            ),
          )),
        ],
      ]),
    );
  }

  Widget _buildDemandes() {
    if (_demandes.isEmpty) {
      return const Center(child: Text('Aucune demande d\'acheteur en cours.',
          style: TextStyle(color: Colors.grey)));
    }
    return RefreshIndicator(
      onRefresh: _charger,
      child: ListView.builder(
        padding: const EdgeInsets.all(8),
        itemCount: _demandes.length,
        itemBuilder: (_, i) {
          final d = _demandes[i];
          return Card(
            child: ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFDEEBFF),
                child: Icon(Icons.shopping_cart_outlined, color: Colors.blue),
              ),
              title: Text(d['produit'] ?? '—', style: const TextStyle(fontWeight: FontWeight.bold)),
              subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${(nombre(d['quantite']) ?? 0).toStringAsFixed(0)} kg'
                    '${d['prix'] != null ? ' — ${(nombre(d['prix']) ?? 0).toStringAsFixed(0)} FCFA' : ''}'),
                if (d['numero_commande'] != null)
                  Text('Commande : ${d['numero_commande']}',
                      style: const TextStyle(fontSize: 11, fontFamily: 'monospace', color: AppTheme.vert)),
              ]),
              trailing: StatusBadge(d['statut'] ?? ''),
            ),
          );
        },
      ),
    );
  }
}
