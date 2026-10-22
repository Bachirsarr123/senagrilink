import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/depot.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/carte_depot.dart';

/// Acheminements vers l'entrepôt assignés au transporteur connecté : mission
/// à accepter ou refuser, puis étapes (chez le producteur, chargement, départ,
/// arrivée), dans l'ordre imposé par le serveur.
class ReservationsScreen extends StatefulWidget {
  const ReservationsScreen({super.key});
  @override
  State<ReservationsScreen> createState() => _ReservationsScreenState();
}

class _ReservationsScreenState extends State<ReservationsScreen> {
  static const _actions = ['accepter_mission', 'refuser_mission', 'arriver_collecte', 'charger', 'partir', 'arriver', 'declarer_incident'];

  List<Map<String, dynamic>> _reservations = [];
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
      // Missions à accepter d'abord, puis celles en cours, puis les terminées.
      int rang(Map<String, dynamic> r) => peut(r, 'accepter_mission') ? 0 : statutsDepotActifs.contains(r['statut']) ? 1 : 2;
      liste.sort((a, b) => rang(a).compareTo(rang(b)));
      setState(() { _reservations = liste; _loading = false; });
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
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Acheminements vers l\'entrepôt')),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? Center(child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(_erreur!, style: const TextStyle(color: AppTheme.rouge), textAlign: TextAlign.center),
                  const SizedBox(height: 12),
                  ElevatedButton(onPressed: _charger, child: const Text('Réessayer')),
                ]),
              ))
            : _reservations.isEmpty
                ? const Center(child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('Aucun acheminement. Les nouvelles missions apparaissent ici dès que l\'entrepôt vous les affecte.',
                        style: TextStyle(color: Colors.grey), textAlign: TextAlign.center),
                  ))
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(8),
                      itemCount: _reservations.length,
                      itemBuilder: (_, i) {
                        final r = _reservations[i];
                        return CarteDepot(
                          reservation: r,
                          actionsAffichees: _actions,
                          vueTransporteur: true,
                          enCours: _actionEnCours == r['id'],
                          onAction: (a) => _agir(r, a),
                        );
                      },
                    ),
                  ),
  );
}
