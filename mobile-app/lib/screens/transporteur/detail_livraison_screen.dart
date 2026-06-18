import 'package:flutter/material.dart';
import '../../models/livraison.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';

class DetailLivraisonScreen extends StatefulWidget {
  final Livraison livraison;
  const DetailLivraisonScreen({super.key, required this.livraison});
  @override
  State<DetailLivraisonScreen> createState() => _DetailLivraisonScreenState();
}

class _DetailLivraisonScreenState extends State<DetailLivraisonScreen> {
  bool _loading = false;
  String? _erreur;
  late String _statut;

  @override
  void initState() { super.initState(); _statut = widget.livraison.statut; }

  Future<void> _updateStatut(String nouveau) async {
    setState(() { _loading = true; _erreur = null; });
    try {
      await ApiService.updateStatutLivraison(widget.livraison.id, nouveau);
      setState(() { _statut = nouveau; _loading = false; });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Statut mis à jour : $nouveau'), backgroundColor: AppTheme.vert));
      }
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _signalerProbleme() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Signaler un problème'),
        content: const Text('Confirmer le signalement d\'un problème sur cette livraison ?\nL\'acheteur sera alerté.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.rouge),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Confirmer'),
          ),
        ],
      ),
    );
    if (confirm != true) return;
    setState(() { _loading = true; _erreur = null; });
    try {
      await ApiService.signalerProbleme(widget.livraison.id);
      setState(() { _statut = 'probleme'; _loading = false; });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Problème signalé.'), backgroundColor: AppTheme.rouge));
        Navigator.pop(context, true);
      }
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Widget _infoRow(String label, String? value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(children: [
      SizedBox(width: 110, child: Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13))),
      Expanded(child: Text(value ?? '—', style: const TextStyle(fontWeight: FontWeight.w500))),
    ]),
  );

  @override
  Widget build(BuildContext context) {
    final l = widget.livraison;
    final commande = l.commande;

    return Scaffold(
      appBar: AppBar(title: Text(l.numeroLivraison), actions: [
        if (_loading) const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))),
      ]),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          // Statut actuel
          Card(child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              const Text('Statut actuel', style: TextStyle(fontWeight: FontWeight.bold)),
              StatusBadge(_statut),
            ]),
          )),

          if (_erreur != null)
            Container(
              margin: const EdgeInsets.symmetric(vertical: 8),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(8), border: Border.all(color: AppTheme.rouge)),
              child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
            ),

          // Infos livraison
          Card(child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Détails', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
              const Divider(),
              _infoRow('Origine', l.origine),
              _infoRow('Destination', l.destination),
              _infoRow('Date départ', l.dateDepart),
              _infoRow('Date livraison', l.dateLivraison),
              if (commande != null) ...[
                const Divider(),
                const Text('Commande associée', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.grey)),
                _infoRow('Numéro', commande['numero_commande']),
                _infoRow('Produit', commande['produit']),
                _infoRow('Quantité', '${commande['quantite']} kg'),
              ],
            ]),
          )),

          const SizedBox(height: 8),

          // Actions
          if (_statut == 'en_attente') ...[
            SizedBox(width: double.infinity, child: ElevatedButton.icon(
              icon: const Icon(Icons.play_arrow),
              label: const Text('Démarrer la livraison'),
              onPressed: _loading ? null : () => _updateStatut('en_cours'),
            )),
            const SizedBox(height: 8),
          ],
          if (_statut == 'en_cours') ...[
            SizedBox(width: double.infinity, child: ElevatedButton.icon(
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('Confirmer la livraison'),
              onPressed: _loading ? null : () => _updateStatut('livree'),
            )),
            const SizedBox(height: 8),
          ],
          if (_statut != 'livree' && _statut != 'probleme')
            SizedBox(width: double.infinity, child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(foregroundColor: AppTheme.rouge, side: const BorderSide(color: AppTheme.rouge)),
              icon: const Icon(Icons.warning_amber_outlined),
              label: const Text('Signaler un problème'),
              onPressed: _loading ? null : _signalerProbleme,
            )),
        ]),
      ),
    );
  }
}
