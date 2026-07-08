import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../acheteur/suivi_livraison_screen.dart';

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
      setState(() { _resultat = res['tracabilite'] as Map<String, dynamic>; _loading = false; });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  // Le suivi en direct n'est accessible qu'au producteur et à l'acheteur (cf. backend PositionGpsController).
  bool get _peutSuivreEnDirect {
    final role = context.read<AuthProvider>().role;
    return role == 'producteur' || role == 'acheteur_gros';
  }

  @override
  Widget build(BuildContext context) {
    final production = _resultat?['etape_production'] as Map<String, dynamic>?;
    final producteur = production?['producteur'] as Map<String, dynamic>?;
    final etapesStockage = (_resultat?['etapes_stockage'] as List?) ?? [];

    return Scaffold(
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

        if (production != null) ...[
          const SizedBox(height: 20),

          // Production
          _SectionCard(
            icon: Icons.agriculture,
            color: AppTheme.vert,
            title: 'Production',
            children: [
              _InfoRow('Code', _resultat!['code_tracabilite'], monospace: true),
              if (producteur != null) ...[
                _InfoRow('Producteur', '${producteur['prenom']} ${producteur['nom']}'),
                _InfoRow('Région', producteur['region']),
              ],
              _InfoRow('Culture', production['type_culture']),
              _InfoRow('Date récolte', production['date_recolte']),
              _InfoRow('Qté estimée', _fmt(production['quantite_estimee'], 'kg')),
              _InfoRow('Qté réelle', _fmt(production['quantite_reelle'], 'kg')),
              _InfoRowBadge('Statut', production['statut']),
            ],
          ),

          // Une section par entrepôt où le lot est passé
          if (etapesStockage.isEmpty) ...[
            const SizedBox(height: 12),
            _EmptyStep(icon: Icons.warehouse_outlined, label: 'Pas encore en entrepôt'),
          ] else ...[
            for (final etape in etapesStockage) ...[
              const SizedBox(height: 12),
              _buildStockage(etape as Map<String, dynamic>),
              ..._buildCommandes((etape['commandes'] as List?) ?? []),
            ],
          ],
        ],
      ]),
    ),
    );
  }

  Widget _buildStockage(Map<String, dynamic> etape) {
    final entrepot = etape['entrepot'] as Map<String, dynamic>?;
    return _SectionCard(
      icon: Icons.warehouse_outlined,
      color: AppTheme.orange,
      title: 'Stockage en entrepôt',
      children: [
        if (entrepot != null) ...[
          _InfoRow('Entrepôt', entrepot['nom']),
          _InfoRow('Localisation', entrepot['localisation']),
        ],
        _InfoRow('Produit', etape['produit']),
        _InfoRow('Quantité', _fmt(etape['quantite'], 'kg')),
        _InfoRow('Date entrée', etape['date_entree']),
        _InfoRowBadge('Statut', etape['statut']),
      ],
    );
  }

  List<Widget> _buildCommandes(List commandes) {
    if (commandes.isEmpty) {
      return [const SizedBox(height: 12), _EmptyStep(icon: Icons.shopping_cart_outlined, label: 'Aucune commande liée')];
    }

    final widgets = <Widget>[];
    for (final c in commandes) {
      final commande = c as Map<String, dynamic>;
      final acheteur = commande['acheteur'] as Map<String, dynamic>?;
      final livraison = commande['livraison'] as Map<String, dynamic>?;

      widgets.add(const SizedBox(height: 12));
      widgets.add(_SectionCard(
        icon: Icons.shopping_cart_outlined,
        color: Colors.blue,
        title: 'Commande',
        children: [
          _InfoRow('Numéro', commande['numero_commande'], monospace: true),
          if (acheteur != null) _InfoRow('Acheteur', '${acheteur['prenom']} ${acheteur['nom']}'),
          _InfoRow('Produit', commande['produit']),
          _InfoRow('Quantité', _fmt(commande['quantite'], 'kg')),
          _InfoRow('Prix', _fmt(commande['prix'], 'FCFA')),
          _InfoRowBadge('Statut', commande['statut']),
        ],
      ));

      widgets.add(const SizedBox(height: 12));
      if (livraison == null) {
        widgets.add(_EmptyStep(icon: Icons.local_shipping_outlined, label: 'Livraison pas encore créée'));
      } else {
        final transporteur = livraison['transporteur'] as Map<String, dynamic>?;
        widgets.add(_SectionCard(
          icon: Icons.local_shipping_outlined,
          color: Colors.purple,
          title: 'Livraison',
          children: [
            _InfoRow('Numéro', livraison['numero_livraison'], monospace: true),
            _InfoRow('Origine', livraison['origine']),
            _InfoRow('Destination', livraison['destination']),
            _InfoRow('Date départ', livraison['date_depart']),
            _InfoRow('Date livraison', livraison['date_livraison']),
            _InfoRowBadge('Statut', livraison['statut']),
            if (transporteur != null) ...[
              _InfoRow('Transporteur', '${transporteur['prenom']} ${transporteur['nom']}'),
              _InfoRow('Véhicule', transporteur['type_vehicule']),
              _InfoRow('Zone', transporteur['zone']),
            ] else
              _InfoRow('Transporteur', 'Pas encore assigné'),
            if (livraison['statut'] == 'en_cours' && _peutSuivreEnDirect) ...[
              const SizedBox(height: 8),
              OutlinedButton.icon(
                icon: const Icon(Icons.map_outlined, size: 16),
                label: const Text('Suivre en direct'),
                onPressed: () => Navigator.push(context, MaterialPageRoute(
                  builder: (_) => SuiviLivraisonScreen(livraisonId: livraison['id']),
                )),
              ),
            ],
          ],
        ));
      }
    }
    return widgets;
  }

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
