import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';

/// Réservations d'entrepôt assignées au transporteur connecté :
/// acheminement de la marchandise du producteur vers l'entrepôt.
class ReservationsScreen extends StatefulWidget {
  const ReservationsScreen({super.key});
  @override
  State<ReservationsScreen> createState() => _ReservationsScreenState();
}

class _ReservationsScreenState extends State<ReservationsScreen> {
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
      setState(() {
        _reservations = ((res['reservations'] as List?) ?? []).cast<Map<String, dynamic>>();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _marquerArrivee(int id) async {
    setState(() { _actionEnCours = id; });
    try {
      await ApiService.marquerArriveeReservation(id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Arrivée signalée. Le gestionnaire a été notifié.'), backgroundColor: AppTheme.vert));
      }
      await _charger();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
      }
    } finally {
      if (mounted) setState(() { _actionEnCours = null; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Réservations entrepôt')),
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
                ? const Center(child: Text('Aucune réservation assignée.', style: TextStyle(color: Colors.grey)))
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(8),
                      itemCount: _reservations.length,
                      itemBuilder: (_, i) {
                        final r = _reservations[i];
                        final statut = r['statut'] as String;
                        return Card(
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                Text(r['numero_reservation'] ?? '',
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
                                StatusBadge(statut),
                              ]),
                              const SizedBox(height: 6),
                              Text(r['produit'] ?? '', style: const TextStyle(fontSize: 15)),
                              Text('${r['quantite_reservee']} kg', style: const TextStyle(color: Colors.grey, fontSize: 13)),
                              if (r['entrepot'] != null)
                                Text('Vers : ${r['entrepot']['nom_entrepot']}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                              if (statut == 'confirmee') ...[
                                const SizedBox(height: 10),
                                SizedBox(
                                  width: double.infinity,
                                  child: ElevatedButton.icon(
                                    icon: const Icon(Icons.check_circle_outline, size: 18),
                                    label: Text(_actionEnCours == r['id'] ? 'Envoi...' : 'Marquer arrivée entrepôt'),
                                    onPressed: _actionEnCours == r['id'] ? null : () => _marquerArrivee(r['id']),
                                  ),
                                ),
                              ],
                            ]),
                          ),
                        );
                      },
                    ),
                  ),
  );
}
