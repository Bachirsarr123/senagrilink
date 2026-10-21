import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Scan du QR code d'un lot (Q13) avec l'appareil photo. Renvoie le texte lu
/// (le code du lot), ou null si l'utilisateur renonce.
class ScannerQr {
  /// Remplaçable dans les tests (aucun appareil photo).
  static Future<String?> Function(BuildContext)? remplacement;

  static Future<String?> scanner(BuildContext context) {
    if (remplacement != null) return remplacement!(context);
    return Navigator.push<String>(context, MaterialPageRoute(builder: (_) => const _EcranScan()));
  }
}

class _EcranScan extends StatefulWidget {
  const _EcranScan();
  @override
  State<_EcranScan> createState() => _EcranScanState();
}

class _EcranScanState extends State<_EcranScan> {
  bool _lu = false;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Scanner le QR code du lot')),
    body: Stack(children: [
      MobileScanner(
        onDetect: (capture) {
          final code = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
          if (code == null || _lu) return;
          _lu = true;
          Navigator.pop(context, code.trim());
        },
        errorBuilder: (context, erreur, _) => const Center(child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('Appareil photo indisponible ou refusé. Saisissez le code du lot à la main.', textAlign: TextAlign.center),
        )),
      ),
      const Align(alignment: Alignment.bottomCenter, child: Padding(
        padding: EdgeInsets.all(24),
        child: Card(child: Padding(padding: EdgeInsets.all(12), child: Text('Visez l\'étiquette du lot.'))),
      )),
    ]),
  );
}
