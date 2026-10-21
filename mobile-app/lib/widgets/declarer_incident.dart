import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/livraison.dart';
import 'app_theme.dart';

/// Fenêtre de déclaration : type et description obligatoires. Renvoie le
/// choix saisi, ou null si l'utilisateur renonce.
Future<({String type, String description})?> saisirIncident(BuildContext context,
    {required String titre, required String consequence, required List<Choix> types}) {
  final ctl = TextEditingController();
  String? type;
  String? erreur;
  return showDialog<({String type, String description})>(
    context: context,
    builder: (c) => StatefulBuilder(builder: (c, setState) => AlertDialog(
      title: Text(titre),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(consequence, style: const TextStyle(fontSize: 13)),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          key: const Key('type-incident'),
          initialValue: type,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Que se passe-t-il ?'),
          items: [for (final t in types) DropdownMenuItem(value: t.code, child: Text(t.libelle))],
          onChanged: (v) => setState(() => type = v),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const Key('description-incident'),
          controller: ctl,
          maxLength: 1000,
          maxLines: 3,
          decoration: InputDecoration(labelText: 'Précisez', errorText: erreur),
        ),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c), child: const Text('Renoncer')),
        ElevatedButton(
          style: ElevatedButton.styleFrom(backgroundColor: AppTheme.rouge),
          onPressed: () {
            if (type == null || ctl.text.trim().isEmpty) { setState(() => erreur = 'Choisissez le type et décrivez la situation.'); return; }
            Navigator.pop(c, (type: type!, description: ctl.text.trim()));
          },
          child: const Text('Envoyer'),
        ),
      ],
    )),
  );
}

/// Déclare un incident de transport (livraison ou dépôt). Vrai si le serveur l'a enregistré.
Future<bool> declarerIncident(BuildContext context, {required String sujetType, required int sujetId, required String numero}) async {
  final messager = ScaffoldMessenger.of(context);
  final saisie = await saisirIncident(context,
      titre: 'Incident — $numero',
      consequence: 'L\'entrepôt est prévenu et décide de la suite. En cas de panne ou d\'accès impossible, le transport est suspendu jusqu\'à sa décision.',
      types: sujetType == 'livraison' ? typesIncidentLivraison : typesIncidentDepot);
  if (saisie == null) return false;
  try {
    await ApiService.declarerIncident({'sujet_type': sujetType, 'sujet_id': sujetId, 'type': saisie.type, 'description': saisie.description},
        cleIdempotence: ApiService.nouvelleCleIdempotence());
    messager.showSnackBar(const SnackBar(content: Text('Incident déclaré. L\'entrepôt a été prévenu.'), backgroundColor: AppTheme.orange));
    return true;
  } on ApiException catch (e) {
    messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    return false;
  }
}
