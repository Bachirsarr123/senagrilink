import 'package:flutter/material.dart';
import '../utils/nombres.dart';
import 'scanner_qr.dart';

/// Contrôle de la réception d'un dépôt : code du lot (scanné ou saisi),
/// quantité pesée, état ; un écart avec la quantité prévue exige un motif.
/// Renvoie les données à envoyer à PUT /reservations/{id}/valider, ou null.
Future<Map<String, dynamic>?> saisirReception(BuildContext context, Map<String, dynamic> r) {
  final prevue = nombre(r['quantite_reservee']) ?? 0;
  final code = TextEditingController();
  final quantite = TextEditingController(text: nombreLisible(prevue));
  final motif = TextEditingController();
  String etat = 'bon';
  String? erreur;

  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => StatefulBuilder(builder: (c, setState) {
      final q = nombre(quantite.text.replaceAll(',', '.'));
      final ecart = q != null && (q - prevue).abs() > 1e-9;
      return AlertDialog(
        title: Text('Réception — ${r['numero_reservation'] ?? ''}'),
        // Largeur fixée : un champ extensible dans une ligne ne peut pas se mesurer seul dans une fenêtre.
        content: SizedBox(width: double.maxFinite, child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('${r['produit'] ?? ''} — ${kg(prevue)} prévus', style: const TextStyle(fontSize: 13)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(key: const Key('code-lot'), controller: code,
                decoration: const InputDecoration(labelText: 'Code du lot (sur l\'étiquette)'))),
            IconButton(
              key: const Key('scanner-lot'),
              tooltip: 'Scanner le QR code',
              icon: const Icon(Icons.qr_code_scanner),
              onPressed: () async {
                final lu = await ScannerQr.scanner(c);
                if (lu != null) setState(() => code.text = lu);
              },
            ),
          ]),
          TextField(key: const Key('quantite-recue'), controller: quantite, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Quantité pesée (kg)'), onChanged: (_) => setState(() {})),
          DropdownButtonFormField<String>(
            initialValue: etat,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'État de la marchandise'),
            items: const [
              DropdownMenuItem(value: 'bon', child: Text('Bon état')),
              DropdownMenuItem(value: 'acceptable', child: Text('Acceptable (quelques défauts)')),
              DropdownMenuItem(value: 'abime', child: Text('Abîmé')),
            ],
            onChanged: (v) => setState(() => etat = v ?? 'bon'),
          ),
          if (ecart) TextField(key: const Key('motif-ecart'), controller: motif,
              decoration: const InputDecoration(labelText: 'Motif de l\'écart (obligatoire)')),
          if (erreur != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(erreur!, style: const TextStyle(color: Colors.red))),
        ]))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: const Text('Renoncer')),
          ElevatedButton(
            onPressed: () {
              if (code.text.trim().isEmpty) { setState(() => erreur = 'Scannez ou saisissez le code du lot.'); return; }
              if (q == null || q <= 0) { setState(() => erreur = 'Indiquez la quantité pesée.'); return; }
              if (ecart && motif.text.trim().isEmpty) { setState(() => erreur = 'Un écart avec la quantité prévue exige un motif.'); return; }
              Navigator.pop(c, {
                'code_lot': code.text.trim(), 'quantite_reelle': q, 'etat_marchandise': etat,
                if (ecart) 'motif': motif.text.trim(),
              });
            },
            child: const Text('Enregistrer la réception'),
          ),
        ],
      );
    }),
  );
}
