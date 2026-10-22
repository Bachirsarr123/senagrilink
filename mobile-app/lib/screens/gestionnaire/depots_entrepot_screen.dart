import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/depot.dart';
import '../../widgets/carte_depot.dart';
import '../../widgets/info_card.dart';

/// Dépôts de l'entrepôt (lot 5) : accepter ou refuser une demande, affecter
/// un transporteur, contrôler la réception (QR code du lot) ou la refuser.
class DepotsEntrepotScreen extends StatefulWidget {
  const DepotsEntrepotScreen({super.key});
  @override
  State<DepotsEntrepotScreen> createState() => _DepotsEntrepotScreenState();
}

class _DepotsEntrepotScreenState extends State<DepotsEntrepotScreen> {
  static const _actions = ['receptionner', 'refuser_reception', 'confirmer', 'refuser', 'assigner', 'retirer_transporteur', 'remettre_en_attente'];

  List<Map<String, dynamic>> _depots = [];
  bool _loading = true;
  String? _erreur;
  int? _actionEnCours;
  bool _termines = false;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getReservations();
      final liste = ((res['reservations'] as List?) ?? []).cast<Map<String, dynamic>>();
      // Ce qui attend une action de l'entrepôt d'abord.
      int rang(Map<String, dynamic> r) => actionsDe(r).any(_actions.contains) ? 0 : statutsDepotActifs.contains(r['statut']) ? 1 : 2;
      liste.sort((a, b) => rang(a).compareTo(rang(b)));
      setState(() { _depots = liste; _loading = false; });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _agir(Map<String, dynamic> r, String action) async {
    setState(() => _actionEnCours = r['id'] as int);
    final fait = await executerActionDepot(context, r, action);
    if (!mounted) return;
    setState(() => _actionEnCours = null);
    if (fait) await _charger();
  }

  @override
  Widget build(BuildContext context) {
    final visibles = _depots.where((r) => _termines || statutsDepotActifs.contains(r['statut'])).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Dépôts'), actions: [
        IconButton(
          tooltip: _termines ? 'Masquer les dépôts terminés' : 'Afficher les dépôts terminés',
          icon: Icon(_termines ? Icons.filter_alt_off_outlined : Icons.history),
          onPressed: () => setState(() => _termines = !_termines),
        ),
      ]),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : visibles.isEmpty
                  ? const EmptyState(message: 'Aucun dépôt en cours.', icon: Icons.warehouse_outlined)
                  : RefreshIndicator(
                      onRefresh: _charger,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(8),
                        itemCount: visibles.length,
                        itemBuilder: (_, i) => CarteDepot(
                          reservation: visibles[i],
                          actionsAffichees: _actions,
                          vueGestionnaire: true,
                          enCours: _actionEnCours == visibles[i]['id'],
                          onAction: (a) => _agir(visibles[i], a),
                        ),
                      ),
                    ),
    );
  }
}
