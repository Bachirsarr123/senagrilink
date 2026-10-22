import 'package:flutter/material.dart';
import '../../models/commande.dart';
import '../../services/api_service.dart';
import '../../utils/livraison.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/carte_depot.dart' show confirmerAction, demanderMotifAction;
import '../../widgets/choix_transporteur.dart';
import '../../widgets/info_card.dart';
import '../../widgets/status_badge.dart';
import '../shared/fiche_commande_screen.dart';

/// Commandes de l'entrepôt (lot 5) : confirmer ou refuser, préparer, marquer
/// prête, organiser la livraison, changer de transporteur, annuler — boutons
/// donnés par le serveur.
class CommandesEntrepotScreen extends StatefulWidget {
  const CommandesEntrepotScreen({super.key});
  @override
  State<CommandesEntrepotScreen> createState() => _CommandesEntrepotScreenState();
}

class _CommandesEntrepotScreenState extends State<CommandesEntrepotScreen> {
  static const _ordre = ['confirmer', 'preparer', 'marquer_prete', 'organiser_livraison', 'changer_transporteur', 'refuser', 'annuler_commande'];
  static const _termines = ['receptionnee', 'annulee'];

  List<Commande> _commandes = [];
  bool _loading = true;
  String? _erreur;
  int? _enCours;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getCommandesEntrepot();
      final liste = ((res['commandes'] as List?) ?? []).map((e) => Commande.fromJson(e)).toList();
      int rang(Commande c) => c.actions.any(_ordre.take(5).contains) ? 0 : _termines.contains(c.statut) ? 2 : 1;
      liste.sort((a, b) => rang(a).compareTo(rang(b)));
      setState(() { _commandes = liste; _loading = false; });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _agir(Commande c, String a) async {
    final messager = ScaffoldMessenger.of(context);
    Future<dynamic> Function()? appel;
    switch (a) {
      case 'confirmer':
        if (!await confirmerAction(context, 'Confirmer la commande ${c.numeroCommande} ? ${kg(c.quantite)} seront réservés dans le stock.')) return;
        appel = () => ApiService.confirmerCommande(c.id);
      case 'preparer':
        appel = () => ApiService.preparerCommande(c.id);
      case 'marquer_prete':
        appel = () => ApiService.marquerCommandePrete(c.id);
      case 'organiser_livraison':
        final t = await choisirTransporteur(context, titre: 'Qui livre la commande ${c.numeroCommande} ?');
        if (t == null) return;
        final cle = ApiService.nouvelleCleIdempotence();
        appel = () => ApiService.organiserLivraison(c.id, t, cleIdempotence: cle);
      case 'changer_transporteur':
        final t = await choisirTransporteur(context, exclure: c.livraison?['transporteur_id'] as int?, titre: 'Nouveau transporteur');
        if (t == null) return;
        appel = () => ApiService.changerTransporteurLivraison(c.livraison!['id'] as int, t);
      case 'refuser' || 'annuler_commande':
        final motif = await demanderMotifAction(context, a == 'refuser' ? 'Refuser la commande ${c.numeroCommande} ?' : 'Annuler la commande ${c.numeroCommande} ?',
            a == 'refuser' ? 'L\'acheteur sera prévenu avec le motif.' : 'La réservation est libérée, la livraison éventuelle annulée et l\'acheteur prévenu.', obligatoire: true);
        if (motif == null) return;
        appel = a == 'refuser' ? () => ApiService.refuserCommande(c.id, motif) : () => ApiService.annulerCommandeEntrepot(c.id, motif);
    }
    if (appel == null) return;
    setState(() => _enCours = c.id);
    try {
      final res = await appel();
      messager.showSnackBar(SnackBar(content: Text((res is Map ? res['message'] : null) ?? 'Enregistré.'), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    if (!mounted) return;
    setState(() => _enCours = null);
    _charger();
  }

  String _libelle(String a) => const {
    'confirmer': 'Confirmer', 'refuser': 'Refuser', 'preparer': 'Lancer la préparation', 'marquer_prete': 'Commande prête',
    'organiser_livraison': 'Organiser la livraison', 'changer_transporteur': 'Changer de transporteur', 'annuler_commande': 'Annuler la commande',
  }[a] ?? a;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Commandes')),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? ErrorState(message: _erreur!, onRetry: _charger)
            : _commandes.isEmpty
                ? const EmptyState(message: 'Aucune commande.', icon: Icons.receipt_long_outlined)
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(8),
                      itemCount: _commandes.length,
                      itemBuilder: (_, i) {
                        final c = _commandes[i];
                        final acheteur = (c.acheteur?['utilisateur'] as Map?);
                        final actions = _ordre.where(c.peut).toList();
                        final incidents = [...c.incidents, ...(((c.livraison?['incidents'] as List?) ?? const []).cast<Map<String, dynamic>>())]
                            .where((x) => x['statut'] == 'ouvert');
                        return Card(child: InkWell(
                          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => FicheCommandeScreen(commandeId: c.id))),
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(children: [
                                Expanded(child: Text(c.numeroCommande, style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace'))),
                                StatusBadge(c.statut),
                              ]),
                              const SizedBox(height: 4),
                              Text('${c.produit} — ${kg(c.quantite)}${c.prix != null ? ' · ${fcfa(c.prix!)}' : ''}', style: const TextStyle(fontSize: 15)),
                              if (acheteur != null) Text('${acheteur['prenom'] ?? ''} ${acheteur['nom'] ?? ''}${acheteur['telephone'] != null ? ' · ${acheteur['telephone']}' : ''}',
                                  style: const TextStyle(fontSize: 12, color: Colors.grey)),
                              if (c.prochaineEtape != null) Text('Étape suivante : ${c.prochaineEtape}', style: const TextStyle(fontSize: 12)),
                              for (final x in incidents)
                                Text('⚠ ${libelleIncident(x['type'] ?? '')} — à décider (onglet Incidents)', style: const TextStyle(fontSize: 12, color: AppTheme.rouge)),
                              if (actions.isNotEmpty) ...[
                                const SizedBox(height: 8),
                                Wrap(spacing: 8, runSpacing: 8, children: [
                                  for (final a in actions)
                                    (a == 'refuser' || a == 'annuler_commande')
                                        ? OutlinedButton(key: Key('commande-${c.id}-$a'), onPressed: _enCours == c.id ? null : () => _agir(c, a), child: Text(_libelle(a)))
                                        : ElevatedButton(key: Key('commande-${c.id}-$a'), onPressed: _enCours == c.id ? null : () => _agir(c, a), child: Text(_libelle(a))),
                                ]),
                              ],
                            ]),
                          ),
                        ));
                      },
                    ),
                  ),
  );
}
