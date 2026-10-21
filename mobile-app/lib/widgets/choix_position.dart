import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'app_theme.dart';

/// Position d'un lieu (exploitation, entrepôt, adresse de livraison) : un appui
/// sur la carte, ou la position actuelle du téléphone. Elle préremplit les
/// missions et sert à l'itinéraire du transporteur. Même rôle que
/// app-choix-position sur le site.
class ChoixPosition extends StatefulWidget {
  final String libelle;
  final LatLng? initiale;
  final ValueChanged<LatLng?> onChange;
  /// Fond de carte affiché (désactivé dans les tests, sans réseau).
  final bool afficherTuiles;

  const ChoixPosition({super.key, required this.libelle, this.initiale, required this.onChange, this.afficherTuiles = true});

  @override
  State<ChoixPosition> createState() => _ChoixPositionState();
}

/// Arrondi à 7 décimales (précision de la colonne en base, ~1 cm).
double _arrondir(double v) => (v * 1e7).roundToDouble() / 1e7;

class _ChoixPositionState extends State<ChoixPosition> {
  final _carte = MapController();
  LatLng? _position;
  bool _recherche = false;
  String? _erreur;

  @override
  void initState() { super.initState(); _position = widget.initiale; }

  void _definir(LatLng p) {
    final arrondie = LatLng(_arrondir(p.latitude), _arrondir(p.longitude));
    setState(() { _position = arrondie; _erreur = null; });
    widget.onChange(arrondie);
  }

  Future<void> _localiser() async {
    setState(() { _recherche = true; _erreur = null; });
    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        setState(() { _erreur = 'Accès à la position refusé : touchez la carte à l\'endroit exact.'; _recherche = false; });
        return;
      }
      final pos = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
      final point = LatLng(pos.latitude, pos.longitude);
      _definir(point);
      _carte.move(point, 15);
    } catch (_) {
      setState(() => _erreur = 'Position introuvable pour le moment : touchez la carte à l\'endroit exact.');
    } finally {
      if (mounted) setState(() => _recherche = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    Row(children: [
      Expanded(child: Text(widget.libelle, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
      TextButton.icon(
        key: const Key('position-actuelle'),
        icon: const Icon(Icons.my_location, size: 16),
        label: Text(_recherche ? 'Localisation…' : 'Ma position'),
        onPressed: _recherche ? null : _localiser,
      ),
    ]),
    ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        height: 220,
        child: FlutterMap(
          mapController: _carte,
          options: MapOptions(
            initialCenter: _position ?? const LatLng(14.69, -17.44),
            initialZoom: _position != null ? 14 : 11,
            onTap: (_, point) => _definir(point),
          ),
          children: [
            if (widget.afficherTuiles)
              TileLayer(urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'com.senagrilink.agri_platform_mobile'),
            if (_position != null)
              MarkerLayer(markers: [
                Marker(point: _position!, width: 28, height: 28,
                    child: const Icon(Icons.location_on, color: AppTheme.vertFonce, size: 28)),
              ]),
          ],
        ),
      ),
    ),
    const SizedBox(height: 4),
    Text(
      _position != null
          ? 'Position enregistrée : ${_position!.latitude.toStringAsFixed(5)}, ${_position!.longitude.toStringAsFixed(5)}'
          : 'Aucune position : touchez la carte à l\'endroit exact.',
      key: const Key('position-texte'),
      style: const TextStyle(fontSize: 12, color: Colors.grey),
    ),
    if (_erreur != null) Text(_erreur!, style: const TextStyle(fontSize: 12, color: AppTheme.rouge)),
  ]);
}
