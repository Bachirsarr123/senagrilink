import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../utils/nombres.dart';

/// Lien de navigation (Google Maps, ou le navigateur à défaut) vers un point.
Uri lienItineraire(double latitude, double longitude) =>
    Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$latitude,$longitude');

/// Bouton « Itinéraire » ; absent si la position du point n'est pas connue.
class BoutonItineraire extends StatelessWidget {
  final Object? latitude;
  final Object? longitude;
  final String libelle;
  const BoutonItineraire({super.key, required this.latitude, required this.longitude, required this.libelle});

  @override
  Widget build(BuildContext context) {
    final lat = nombre(latitude), lng = nombre(longitude);
    if (lat == null || lng == null) return const SizedBox.shrink();
    return OutlinedButton.icon(
      key: const Key('itineraire'),
      icon: const Icon(Icons.directions_outlined),
      label: Text(libelle),
      onPressed: () async {
        final messager = ScaffoldMessenger.of(context);
        if (!await launchUrl(lienItineraire(lat, lng), mode: LaunchMode.externalApplication)) {
          messager.showSnackBar(const SnackBar(content: Text('Impossible d\'ouvrir la navigation sur ce téléphone.')));
        }
      },
    );
  }
}
