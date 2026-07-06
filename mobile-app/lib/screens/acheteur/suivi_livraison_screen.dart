import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import '../../services/api_service.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';

const _intervalleRafraichissement = Duration(seconds: 10);

/// Carte de suivi en direct d'une livraison : position du transporteur,
/// point de départ (entrepôt) et point d'arrivée (adresse de livraison),
/// actualisés toutes les 10 secondes.
class SuiviLivraisonScreen extends StatefulWidget {
  final int livraisonId;
  const SuiviLivraisonScreen({super.key, required this.livraisonId});

  @override
  State<SuiviLivraisonScreen> createState() => _SuiviLivraisonScreenState();
}

class _SuiviLivraisonScreenState extends State<SuiviLivraisonScreen> {
  final MapController _mapController = MapController();
  Timer? _timer;

  LatLng? _transporteur;
  LatLng? _origine;
  LatLng? _destination;
  String? _statutLivraison;
  String? _erreur;
  bool _premierChargement = true;

  @override
  void initState() {
    super.initState();
    _rafraichir();
    _timer = Timer.periodic(_intervalleRafraichissement, (_) => _rafraichir());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _rafraichir() async {
    try {
      final res = await ApiService.getPositionLivraison(widget.livraisonId);
      final position = res['position'];
      final origine = res['origine'];
      final destination = res['destination'];

      setState(() {
        _erreur = null;
        _statutLivraison = res['statut_livraison'];
        _transporteur = LatLng(
          (position['latitude'] as num).toDouble(),
          (position['longitude'] as num).toDouble(),
        );
        _origine = _versLatLng(origine);
        _destination = _versLatLng(destination);
        _premierChargement = false;
      });

      if (_transporteur != null) {
        _mapController.move(_transporteur!, _mapController.camera.zoom);
      }
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _premierChargement = false; });
    } catch (_) {
      setState(() { _erreur = 'Position indisponible pour le moment.'; _premierChargement = false; });
    }
  }

  LatLng? _versLatLng(Map<String, dynamic>? point) {
    if (point == null || point['latitude'] == null || point['longitude'] == null) return null;
    return LatLng((point['latitude'] as num).toDouble(), (point['longitude'] as num).toDouble());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Suivi de livraison')),
      body: _premierChargement
          ? const Center(child: CircularProgressIndicator())
          : Column(children: [
              if (_statutLivraison != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  color: Colors.white,
                  child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    const Text('Statut', style: TextStyle(fontWeight: FontWeight.bold)),
                    StatusBadge(_statutLivraison!),
                  ]),
                ),
              if (_erreur != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  color: Colors.red.shade50,
                  child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
                ),
              Expanded(child: _transporteur == null ? const SizedBox() : _carte()),
            ]),
    );
  }

  Widget _carte() {
    final points = <LatLng>[
      ?_origine,
      _transporteur!,
      ?_destination,
    ];

    return FlutterMap(
      mapController: _mapController,
      options: MapOptions(initialCenter: _transporteur!, initialZoom: 13),
      children: [
        TileLayer(
          urlTemplate: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
          userAgentPackageName: 'com.senagrilink.agri_platform_mobile',
        ),
        if (points.length > 1)
          PolylineLayer(polylines: [
            Polyline(points: points, color: AppTheme.vert, strokeWidth: 3),
          ]),
        MarkerLayer(markers: [
          if (_origine != null)
            Marker(point: _origine!, width: 40, height: 40, child: const Text('🏭', style: TextStyle(fontSize: 26))),
          if (_destination != null)
            Marker(point: _destination!, width: 40, height: 40, child: const Text('🏠', style: TextStyle(fontSize: 26))),
          Marker(
            point: _transporteur!,
            width: 40,
            height: 40,
            child: const Icon(Icons.local_shipping, color: AppTheme.rouge, size: 32),
          ),
        ]),
      ],
    );
  }
}
