import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/nombres.dart';

/// Choix d'un transporteur, les moins occupés d'abord (charge renvoyée par le
/// serveur). Renvoie son identifiant, ou null si le gestionnaire renonce.
Future<int?> choisirTransporteur(BuildContext context, {int? exclure, String titre = 'Choisir le transporteur'}) async {
  final messager = ScaffoldMessenger.of(context);
  List<Map<String, dynamic>> liste;
  try {
    final res = await ApiService.getTransporteurs();
    liste = ((res['transporteurs'] as List?) ?? const []).cast<Map<String, dynamic>>();
  } on ApiException catch (e) {
    messager.showSnackBar(SnackBar(content: Text(e.message)));
    return null;
  }
  if (!context.mounted) return null;
  return showModalBottomSheet<int>(
    context: context,
    isScrollControlled: true,
    builder: (c) => SafeArea(child: ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(c).height * .7),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(padding: const EdgeInsets.all(16), child: Text(titre, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold))),
        if (liste.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('Aucun transporteur disponible.')),
        Flexible(child: ListView(shrinkWrap: true, children: [
          for (final t in liste)
            ListTile(
              key: Key('transporteur-${t['id']}'),
              enabled: t['id'] != exclure,
              leading: const Icon(Icons.local_shipping_outlined),
              title: Text('${(t['utilisateur'] as Map?)?['prenom'] ?? ''} ${(t['utilisateur'] as Map?)?['nom'] ?? ''}'),
              subtitle: Text('${t['type_vehicule'] ?? 'Véhicule non précisé'} · ${nombreLisible(nombre(t['missions_actives']) ?? 0)} mission(s) en cours'),
              onTap: () => Navigator.pop(c, t['id'] as int),
            ),
        ])),
      ]),
    )),
  );
}
