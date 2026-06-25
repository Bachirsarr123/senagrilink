import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';

class TracabiliteScreen extends StatefulWidget {
  const TracabiliteScreen({super.key});
  @override
  State<TracabiliteScreen> createState() => _TracabiliteScreenState();
}

class _TracabiliteScreenState extends State<TracabiliteScreen> {
  final _codeCtl = TextEditingController();
  bool _loading = false;
  String? _erreur;
  Map<String, dynamic>? _resultat;

  @override
  void dispose() { _codeCtl.dispose(); super.dispose(); }

  Future<void> _rechercher() async {
    final code = _codeCtl.text.trim().toUpperCase();
    if (code.isEmpty) return;
    setState(() { _loading = true; _erreur = null; _resultat = null; });
    try {
      final res = await ApiService.getTracabilite(code);
      setState(() { _resultat = res; _loading = false; });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Traçabilité d\'un lot')),
    body: SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Barre de recherche
        Row(children: [
          Expanded(
            child: TextField(
              controller: _codeCtl,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(
                labelText: 'Code de traçabilité',
                hintText: 'ex : LOT-2026-00001',
                prefixIcon: Icon(Icons.qr_code_scanner_outlined),
              ),
              onSubmitted: (_) => _rechercher(),
            ),
          ),
          const SizedBox(width: 12),
          ElevatedButton(
            onPressed: _loading ? null : _rechercher,
            child: _loading
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Chercher'),
          ),
        ]),

        if (_erreur != null) ...[
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(8), border: Border.all(color: AppTheme.rouge)),
            child: Row(children: [
              const Icon(Icons.error_outline, color: AppTheme.rouge, size: 18),
              const SizedBox(width: 8),
              Expanded(child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge))),
            ]),
          ),
        ],

        if (_resultat != null) ...[
          const SizedBox(height: 20),

          // Production
          _SectionCard(
            icon: Icons.agriculture,
            color: AppTheme.vert,
            title: 'Production',
            children: [
              _InfoRow('Code', _resultat!['production']['code_tracabilite'], monospace: true),
              _InfoRow('Culture', _resultat!['production']['type_culture']),
              _InfoRow('Date récolte', _resultat!['production']['date_recolte']),
              _InfoRow('Qté estimée', _fmt(_resultat!['production']['quantite_estimee'], 'kg')),
              _InfoRow('Qté réelle', _fmt(_resultat!['production']['quantite_reelle'], 'kg')),
              _InfoRowBadge('Statut', _resultat!['production']['statut']),
            ],
          ),

          // Stock
          if (_resultat!['stock'] != null) ...[
            const SizedBox(height: 12),
            _SectionCard(
              icon: Icons.warehouse_outlined,
              color: AppTheme.orange,
              title: 'Stockage en entrepôt',
              children: [
                if (_resultat!['stock']['entrepot'] != null) ...[
                  _InfoRow('Entrepôt', _resultat!['stock']['entrepot']['nom_entrepot']),
                  _InfoRow('Localisation', _resultat!['stock']['entrepot']['localisation']),
                ],
                _InfoRow('Produit', _resultat!['stock']['produit']),
                _InfoRow('Quantité', _fmt(_resultat!['stock']['quantite'], 'kg')),
                _InfoRow('Date entrée', _resultat!['stock']['date_entree']),
                _InfoRowBadge('Statut', _resultat!['stock']['statut']),
              ],
            ),
          ] else ...[
            const SizedBox(height: 12),
            _EmptyStep(icon: Icons.warehouse_outlined, label: 'Pas encore en entrepôt'),
          ],

          // Commande
          if (_resultat!['commande'] != null) ...[
            const SizedBox(height: 12),
            _SectionCard(
              icon: Icons.shopping_cart_outlined,
              color: Colors.blue,
              title: 'Commande',
              children: [
                _InfoRow('Numéro', _resultat!['commande']['numero_commande'], monospace: true),
                _InfoRow('Produit', _resultat!['commande']['produit']),
                _InfoRow('Quantité', _fmt(_resultat!['commande']['quantite'], 'kg')),
                _InfoRow('Prix', _fmt(_resultat!['commande']['prix'], 'FCFA')),
                _InfoRowBadge('Statut', _resultat!['commande']['statut']),
              ],
            ),
          ] else if (_resultat!['stock'] != null) ...[
            const SizedBox(height: 12),
            _EmptyStep(icon: Icons.shopping_cart_outlined, label: 'Aucune commande liée'),
          ],

          // Livraison
          if (_resultat!['livraison'] != null) ...[
            const SizedBox(height: 12),
            _SectionCard(
              icon: Icons.local_shipping_outlined,
              color: Colors.purple,
              title: 'Livraison',
              children: [
                _InfoRow('Numéro', _resultat!['livraison']['numero_livraison'], monospace: true),
                _InfoRow('Origine', _resultat!['livraison']['origine']),
                _InfoRow('Destination', _resultat!['livraison']['destination']),
                _InfoRow('Date départ', _resultat!['livraison']['date_depart']),
                _InfoRow('Date livraison', _resultat!['livraison']['date_livraison']),
                _InfoRowBadge('Statut', _resultat!['livraison']['statut']),
              ],
            ),
          ] else if (_resultat!['commande'] != null) ...[
            const SizedBox(height: 12),
            _EmptyStep(icon: Icons.local_shipping_outlined, label: 'Livraison pas encore créée'),
          ],
        ],
      ]),
    ),
  );

  String _fmt(dynamic val, String unit) {
    if (val == null) return '—';
    return '${val is double ? val.toStringAsFixed(0) : val} $unit';
  }
}

class _SectionCard extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final List<Widget> children;
  const _SectionCard({required this.icon, required this.color, required this.title, required this.children});

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          CircleAvatar(backgroundColor: color.withAlpha(30), child: Icon(icon, color: color, size: 20)),
          const SizedBox(width: 10),
          Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
        ]),
        const Divider(height: 20),
        ...children,
      ]),
    ),
  );
}

class _InfoRow extends StatelessWidget {
  final String label;
  final dynamic value;
  final bool monospace;
  const _InfoRow(this.label, this.value, {this.monospace = false});

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(width: 110, child: Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13))),
      Expanded(child: Text(
        value?.toString() ?? '—',
        style: TextStyle(fontWeight: FontWeight.w500, fontFamily: monospace ? 'monospace' : null),
      )),
    ]),
  );
}

class _InfoRowBadge extends StatelessWidget {
  final String label;
  final String? value;
  const _InfoRowBadge(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(children: [
      SizedBox(width: 110, child: Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13))),
      if (value != null) StatusBadge(value!) else const Text('—'),
    ]),
  );
}

class _EmptyStep extends StatelessWidget {
  final IconData icon;
  final String label;
  const _EmptyStep({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) => Card(
    color: Colors.grey.shade50,
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Row(children: [
        Icon(icon, color: Colors.grey, size: 20),
        const SizedBox(width: 10),
        Text(label, style: const TextStyle(color: Colors.grey)),
      ]),
    ),
  );
}
