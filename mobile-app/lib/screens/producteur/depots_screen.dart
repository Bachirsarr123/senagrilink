import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/depot.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/carte_depot.dart';
import '../../widgets/info_card.dart';
import 'nouveau_depot_screen.dart';

/// Dépôts du producteur en entrepôt : demande, suivi des étapes, départ et
/// arrivée s'il livre lui-même, annulation jusqu'au départ.
class DepotsScreen extends StatefulWidget {
  const DepotsScreen({super.key});
  @override
  State<DepotsScreen> createState() => _DepotsScreenState();
}

class _DepotsScreenState extends State<DepotsScreen> {
  static const _actions = ['partir', 'arriver', 'annuler'];

  List<Map<String, dynamic>> _depots = [];
  bool _loading = true;
  String? _erreur;
  int? _actionEnCours;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getReservations();
      final liste = ((res['reservations'] as List?) ?? []).cast<Map<String, dynamic>>();
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

  Future<void> _nouveau() async {
    final cree = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => const NouveauDepotScreen()));
    if (cree == true) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Demande de dépôt envoyée. L\'entrepôt a été prévenu.'), backgroundColor: AppTheme.vert));
      }
      _charger();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Mes dépôts en entrepôt')),
    floatingActionButton: FloatingActionButton.extended(
      key: const Key('nouveau-depot'),
      backgroundColor: AppTheme.vert,
      foregroundColor: Colors.white,
      icon: const Icon(Icons.add),
      label: const Text('Déposer une récolte'),
      onPressed: _nouveau,
    ),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? ErrorState(message: _erreur!, onRetry: _charger)
            : _depots.isEmpty
                ? const EmptyState(message: 'Aucun dépôt. Déposez une récolte pour la vendre.', icon: Icons.warehouse_outlined)
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.fromLTRB(8, 8, 8, 88),
                      itemCount: _depots.length,
                      itemBuilder: (_, i) {
                        final r = _depots[i];
                        return CarteDepot(
                          reservation: r,
                          actionsAffichees: _actions,
                          enCours: _actionEnCours == r['id'],
                          onAction: (a) => _agir(r, a),
                        );
                      },
                    ),
                  ),
  );
}
