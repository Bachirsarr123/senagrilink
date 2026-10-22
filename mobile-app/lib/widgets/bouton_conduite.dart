import 'package:flutter/material.dart';
import '../screens/conduite/conduite_screen.dart';

/// Gros bouton « Conduire » (lot 9) : ouvre le guidage dans l'application,
/// à la place de Google Maps.
class BoutonConduite extends StatelessWidget {
  final String type;
  final int id;
  final String numero;
  final String libelle;
  /// Appelé au retour de l'écran Conduite (pour relire la mission).
  final VoidCallback? auRetour;
  const BoutonConduite({super.key, required this.type, required this.id, required this.numero, required this.libelle, this.auRetour});

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 52,
        child: ElevatedButton.icon(
          key: const Key('conduire'),
          icon: const Icon(Icons.navigation),
          label: Text(libelle, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          onPressed: () async {
            await Navigator.push(context, MaterialPageRoute(builder: (_) => ConduiteScreen(type: type, id: id, numero: numero)));
            auRetour?.call();
          },
        ),
      );
}
