import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/depot.dart';
import '../utils/nombres.dart';
import 'app_theme.dart';
import 'declarer_incident.dart';
import 'choix_transporteur.dart';
import 'reception_depot.dart';
import 'itineraire.dart';
import '../screens/acheteur/suivi_livraison_screen.dart';
import '../services/suivi_gps_service.dart';
import '../utils/livraison.dart' show libelleIncident;
import 'status_badge.dart';

/// Carte d'un dépôt (réservation d'entrepôt), commune au transporteur et au
/// producteur. Seules les actions renvoyées par le serveur et listées dans
/// [actionsAffichees] sont proposées.
class CarteDepot extends StatelessWidget {
  final Map<String, dynamic> reservation;
  final List<String> actionsAffichees;
  final bool enCours;
  final void Function(String action) onAction;
  /// Côté transporteur : coordonnées du producteur chez qui aller.
  final bool vueTransporteur;
  /// Côté gestionnaire : producteur (contact) et quantité reçue.
  final bool vueGestionnaire;

  const CarteDepot({
    super.key,
    required this.reservation,
    required this.actionsAffichees,
    required this.onAction,
    this.enCours = false,
    this.vueTransporteur = false,
    this.vueGestionnaire = false,
  });

  @override
  Widget build(BuildContext context) {
    final r = reservation;
    final statut = r['statut'] as String? ?? '';
    final actions = actionsAffichees.where((a) => peut(r, a)).toList();
    final aAccepter = peut(r, 'accepter_mission');
    final producteur = (r['producteur'] as Map?)?['utilisateur'] as Map?;
    final transporteur = (r['transporteur'] as Map?)?['utilisateur'] as Map?;
    final lot = (r['production'] as Map?)?['code_tracabilite'] as String?;
    final prochaine = (r['prochaine_etape'] as Map?)?['libelle'] as String?;
    final quantite = nombre(r['quantite_reservee']) ?? 0;
    final recue = nombre(r['quantite_recue']);

    Widget ligne(IconData icone, String texte) => Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icone, size: 15, color: Colors.grey),
            const SizedBox(width: 6),
            Expanded(child: Text(texte, style: const TextStyle(fontSize: 13))),
          ]),
        );

    return Card(
      shape: aAccepter
          ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: const BorderSide(color: AppTheme.orange, width: 2))
          : null,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(r['numero_reservation'] ?? '',
                style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace'))),
            const SizedBox(width: 8),
            aAccepter
                ? const Text('Nouvelle mission à accepter', style: TextStyle(color: AppTheme.orange, fontWeight: FontWeight.bold, fontSize: 12))
                : StatusBadge(statut),
          ]),
          const SizedBox(height: 6),
          Text('${r['produit'] ?? ''} — ${kg(quantite)}', style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
          if (recue != null && statut == 'enregistree') ligne(Icons.scale_outlined, '${kg(recue)} reçus'),
          if (lot != null) ligne(Icons.qr_code_2, 'Lot $lot'),
          if ((vueTransporteur || vueGestionnaire) && producteur != null) ...[
            ligne(Icons.agriculture, 'Chez ${producteur['prenom'] ?? ''} ${producteur['nom'] ?? ''}'
                '${producteur['telephone'] != null ? ' · ${producteur['telephone']}' : ''}'),
            if (producteur['adresse'] != null) ligne(Icons.place_outlined, producteur['adresse'] as String),
          ],
          if (r['entrepot'] != null)
            ligne(Icons.warehouse_outlined, 'Entrepôt ${r['entrepot']['nom_entrepot'] ?? ''}'
                '${r['entrepot']['localisation'] != null ? ' · ${r['entrepot']['localisation']}' : ''}'),
          if (!vueTransporteur) ...[
            ligne(Icons.local_shipping_outlined, libelleMode[r['mode_transport']] ?? (r['mode_transport'] as String? ?? '')),
            if (transporteur != null)
              ligne(Icons.person_outline, '${transporteur['prenom'] ?? ''} ${transporteur['nom'] ?? ''}'
                  '${transporteur['telephone'] != null ? ' · ${transporteur['telephone']}' : ''}'),
          ],
          for (final i in (r['incidents'] as List?) ?? const [])
            ligne(Icons.warning_amber_outlined, 'Incident : ${libelleIncident((i as Map)['type'] ?? '')} — en attente de l\'entrepôt'),
          if (prochaine != null) ligne(Icons.flag_outlined, 'Étape suivante : $prochaine'),
          if (statut == 'en_route' && !vueTransporteur && r['mode_transport'] != 'producteur')
            Padding(padding: const EdgeInsets.only(top: 8), child: OutlinedButton.icon(
              key: Key('suivre-${r['id']}'),
              icon: const Icon(Icons.map_outlined, size: 16),
              label: const Text('Suivre en direct'),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SuiviLivraisonScreen.mission(type: 'reservation', id: r['id'] as int))),
            )),
          if (statut == 'en_route' && SuiviGpsService.instance.suit('reservation', r['id'] as int))
            ligne(Icons.gps_fixed, 'Position partagée avec l\'entrepôt jusqu\'à l\'arrivée'),
          if (vueTransporteur && ['prise_en_charge', 'au_point_collecte'].contains(statut))
            Padding(padding: const EdgeInsets.only(top: 8), child: BoutonItineraire(
                // Lieu de prise en charge choisi au dépôt (lot 8), sinon lieu habituel du producteur
                latitude: r['collecte_latitude'] ?? (r['producteur'] as Map?)?['latitude'],
                longitude: r['collecte_longitude'] ?? (r['producteur'] as Map?)?['longitude'],
                libelle: 'Itinéraire vers le producteur')),
          if (['chargee', 'en_route'].contains(statut) && (vueTransporteur || r['mode_transport'] == 'producteur') || (statut == 'confirmee' && r['mode_transport'] == 'producteur'))
            Padding(padding: const EdgeInsets.only(top: 8), child: BoutonItineraire(
                latitude: (r['entrepot'] as Map?)?['latitude'], longitude: (r['entrepot'] as Map?)?['longitude'], libelle: 'Itinéraire vers l\'entrepôt')),
          if (actions.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final a in actions)
                const ['refuser_mission', 'annuler', 'declarer_incident', 'refuser', 'refuser_reception', 'remettre_en_attente', 'retirer_transporteur'].contains(a)
                    ? OutlinedButton(
                        key: Key('depot-${r['id']}-$a'),
                        onPressed: enCours ? null : () => onAction(a),
                        child: Text(libelleAction[a] ?? a),
                      )
                    : ElevatedButton(
                        key: Key('depot-${r['id']}-$a'),
                        onPressed: enCours ? null : () => onAction(a),
                        child: Text(enCours ? 'Envoi…' : (a == 'assigner' && r['transporteur_id'] != null ? 'Changer de transporteur' : (libelleAction[a] ?? a))),
                      ),
            ]),
          ],
        ]),
      ),
    );
  }
}

/// Exécute une action de dépôt après confirmation (ou motif). Renvoie vrai si
/// le serveur l'a enregistrée ; le message du serveur est affiché dans les deux cas.
Future<bool> executerActionDepot(BuildContext context, Map<String, dynamic> r, String action) async {
  final id = r['id'] as int;
  final numero = r['numero_reservation'] ?? '';
  final messager = ScaffoldMessenger.of(context);

  Future<dynamic> Function()? appel;
  if (action == 'accepter_mission') {
    if (!await _confirmer(context, 'Accepter la mission $numero : ${r['produit'] ?? ''} à aller chercher chez le producteur ?')) return false;
    appel = () => ApiService.accepterMission(id);
  } else if (action == 'refuser_mission') {
    if (!context.mounted) return false;
    final motif = await _demanderMotif(context, 'Refuser la mission $numero ?', 'Elle sera rendue à l\'entrepôt, qui affectera un autre transporteur.', obligatoire: true);
    if (motif == null) return false;
    appel = () => ApiService.refuserMission(id, motif);
  } else if (action == 'annuler') {
    if (!context.mounted) return false;
    final motif = await _demanderMotif(context, 'Annuler le dépôt $numero ?',
        'L\'entrepôt${r['transporteur_id'] != null ? ' et le transporteur seront prévenus' : ' sera prévenu'}. Cette annulation est définitive.', obligatoire: false);
    if (motif == null) return false;
    appel = () => ApiService.annulerDepot(id, motif: motif);
  } else if (action == 'confirmer') {
    if (!await _confirmer(context, 'Accepter le dépôt $numero : ${r['produit'] ?? ''} (${r['quantite_reservee'] ?? ''} kg) ?')) return false;
    appel = () => ApiService.confirmerDepot(id);
  } else if (action == 'refuser' || action == 'refuser_reception') {
    if (!context.mounted) return false;
    final motif = await _demanderMotif(context, action == 'refuser' ? 'Refuser le dépôt $numero ?' : 'Refuser la marchandise $numero ?',
        action == 'refuser' ? 'Le producteur sera prévenu avec le motif.' : 'Aucun stock ne sera créé ; la quantité revient au lot du producteur.', obligatoire: true);
    if (motif == null) return false;
    appel = action == 'refuser' ? () => ApiService.refuserDepot(id, motif) : () => ApiService.refuserReceptionDepot(id, motif);
  } else if (action == 'remettre_en_attente') {
    if (!await _confirmer(context, 'Annuler l\'acceptation du dépôt $numero ? Il redevient une demande à examiner.')) return false;
    appel = () => ApiService.remettreDepotEnAttente(id);
  } else if (action == 'assigner') {
    if (!context.mounted) return false;
    final t = await choisirTransporteur(context, exclure: r['transporteur_id'] as int?);
    if (t == null) return false;
    appel = () => ApiService.assignerTransporteurDepot(id, t);
  } else if (action == 'retirer_transporteur') {
    if (!await _confirmer(context, 'Retirer la mission au transporteur ? Il sera prévenu.')) return false;
    appel = () => ApiService.retirerTransporteurDepot(id);
  } else if (action == 'receptionner') {
    if (!context.mounted) return false;
    final donnees = await saisirReception(context, r);
    if (donnees == null) return false;
    appel = () => ApiService.receptionnerDepot(id, donnees);
  } else if (action == 'declarer_incident') {
    return declarerIncident(context, sujetType: 'reservation', sujetId: id, numero: numero);
  } else if (etapeDeLAction.containsKey(action)) {
    if (!await _confirmer(context, confirmationEtape[action] ?? 'Confirmer ?')) return false;
    appel = () => ApiService.etapeDepot(id, etapeDeLAction[action]!);
  }
  if (appel == null) return false;

  try {
    final res = await appel();
    messager.showSnackBar(SnackBar(content: Text((res is Map ? res['message'] : null) ?? 'Enregistré.'), backgroundColor: AppTheme.vert));
    // Lot 4 : partage de position pendant le trajet vers l'entrepôt, même écran quitté.
    if (action == 'partir') {
      final ok = await SuiviGpsService.instance.demarrer((type: 'reservation', id: id, numero: numero as String));
      if (!ok) {
        messager.showSnackBar(const SnackBar(content: Text('Position GPS indisponible : activez la localisation pour partager votre trajet.'), backgroundColor: AppTheme.orange));
      }
    } else if (action == 'arriver' && SuiviGpsService.instance.suit('reservation', id)) {
      await SuiviGpsService.instance.arreter();
    }
    return true;
  } on ApiException catch (e) {
    messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    return false;
  }
}

/// Confirmation simple (Oui / Non), commune aux écrans de transport.
Future<bool> confirmerAction(BuildContext context, String question) => _confirmer(context, question);

/// Motif saisi (obligatoire ou non) ; null si l'utilisateur renonce.
Future<String?> demanderMotifAction(BuildContext context, String titre, String consequence, {required bool obligatoire}) =>
    _demanderMotif(context, titre, consequence, obligatoire: obligatoire);

Future<bool> _confirmer(BuildContext context, String question) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        content: Text(question),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Non')),
          ElevatedButton(onPressed: () => Navigator.pop(c, true), child: const Text('Oui, confirmer')),
        ],
      ),
    ) ??
    false;

/// Motif saisi ; null si l'utilisateur renonce. Un motif obligatoire ne peut pas être vide.
Future<String?> _demanderMotif(BuildContext context, String titre, String consequence, {required bool obligatoire}) {
  final ctl = TextEditingController();
  String? erreur;
  return showDialog<String>(
    context: context,
    builder: (c) => StatefulBuilder(builder: (c, setState) {
      return AlertDialog(
        title: Text(titre),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(consequence, style: const TextStyle(fontSize: 13)),
          const SizedBox(height: 12),
          TextField(
            key: const Key('motif'),
            controller: ctl,
            maxLength: 500,
            decoration: InputDecoration(labelText: obligatoire ? 'Motif (obligatoire)' : 'Motif (facultatif)', errorText: erreur),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: const Text('Renoncer')),
          ElevatedButton(
            onPressed: () {
              final motif = ctl.text.trim();
              if (obligatoire && motif.isEmpty) { setState(() => erreur = 'Indiquez le motif.'); return; }
              Navigator.pop(c, motif);
            },
            child: const Text('Confirmer'),
          ),
        ],
      );
    }),
  );
}
