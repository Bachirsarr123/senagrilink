import 'package:flutter/material.dart';
import '../../models/commande.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../utils/paiement.dart';
import '../../widgets/app_theme.dart';
import '../paiement/paiement_screen.dart';
import '../../widgets/status_badge.dart';
import '../../widgets/info_card.dart';
import 'suivi_livraison_screen.dart';
import '../../utils/livraison.dart';
import '../../widgets/carte_depot.dart' show confirmerAction, demanderMotifAction;
import '../../widgets/declarer_incident.dart' show saisirIncident;

class MesCommandesScreen extends StatefulWidget {
  const MesCommandesScreen({super.key});
  @override
  State<MesCommandesScreen> createState() => _MesCommandesScreenState();
}

class _MesCommandesScreenState extends State<MesCommandesScreen> {
  List<Commande> _commandes = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getHistoriqueCommandes();
      setState(() {
        _commandes = (res['commandes'] as List).map((e) => Commande.fromJson(e)).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  Future<void> _annuler(Commande c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Annuler la commande'),
        content: Text('Annuler la commande ${c.numeroCommande} ?\nCette action est irréversible.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Non')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.rouge),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Annuler la commande'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ApiService.annulerCommande(c.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Commande annulée.'), backgroundColor: AppTheme.rouge));
        _charger();
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
      }
    }
  }

  Future<void> _modifier(Commande c) async {
    final ctl = TextEditingController(text: nombreLisible(c.quantite));
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Modifier la commande'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('Commande : ${c.numeroCommande}',
              style: const TextStyle(fontSize: 13, color: Colors.grey)),
          if (c.prixUnitaire != null)
            Text('Prix figé à la commande : ${fcfa(c.prixUnitaire!)} / kg',
                style: const TextStyle(fontSize: 13, color: Colors.grey)),
          const SizedBox(height: 12),
          TextField(
            controller: ctl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Nouvelle quantité (kg)',
              suffixText: 'kg',
            ),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Enregistrer'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final qte = nombre(ctl.text.trim().replaceAll(',', '.'));
    if (qte == null || qte <= 0) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Quantité invalide.'), backgroundColor: AppTheme.rouge));
      return;
    }
    try {
      await ApiService.updateCommande(c.id, {'quantite': qte});
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Commande mise à jour.'), backgroundColor: AppTheme.vert));
        _charger();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
  }

  Future<void> _appeler(Future<dynamic> Function() appel, String succes) async {
    final messager = ScaffoldMessenger.of(context);
    try {
      final res = await appel();
      messager.showSnackBar(SnackBar(content: Text((res is Map ? res['message'] : null) ?? succes), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    _charger();
  }

  Future<void> _payer(Commande c) async {
    final messager = ScaffoldMessenger.of(context);
    try {
      final message = await payerCommandes(context, [c.id], c.totalAPayer ?? 0);
      if (message != null) messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
    _charger();
  }

  Future<void> _confirmerReception(Commande c) async {
    if (!await confirmerAction(context, 'Confirmer que vous avez bien reçu la commande ${c.numeroCommande} (${kg(c.quantite)} de ${c.produit}) ?\n'
        'En cas de problème, utilisez plutôt « Signaler un problème ».')) { return; }
    await _appeler(() => ApiService.confirmerReception(c.id), 'Réception confirmée.');
  }

  Future<void> _demanderAnnulation(Commande c) async {
    final motif = await demanderMotifAction(context, 'Demander l\'annulation de ${c.numeroCommande} ?',
        'L\'entrepôt décide. L\'annulation n\'est plus possible une fois la marchandise chargée.', obligatoire: false);
    if (motif == null) return;
    await _appeler(() => ApiService.demanderAnnulation(c.id, motif: motif), 'Demande envoyée.');
  }

  Future<void> _anomalie(Commande c) async {
    final saisie = await saisirIncident(context, titre: 'Problème à la réception — ${c.numeroCommande}',
        consequence: 'La livraison est enregistrée et la commande passe en litige : l\'entrepôt vous propose une solution.', types: anomalies);
    if (saisie == null) return;
    await _appeler(() => ApiService.declarerAnomalie(c.id, saisie.type, saisie.description), 'Problème signalé.');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Mes commandes')),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? ErrorState(message: _erreur!, onRetry: _charger)
            : _commandes.isEmpty
                ? const EmptyState(message: 'Aucune commande passée.', icon: Icons.receipt_long_outlined)
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      padding: const EdgeInsets.all(8),
                      itemCount: _commandes.length,
                      itemBuilder: (_, i) {
                        final c = _commandes[i];
                        return Card(
                          color: c.alerteLivraison ? Colors.red.shade50 : null,
                          child: Padding(
                            padding: const EdgeInsets.all(12),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                Expanded(child: Text(c.numeroCommande,
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace'))),
                                const SizedBox(width: 8),
                                StatusBadge(c.statut),
                              ]),
                              const SizedBox(height: 6),
                              Text(c.produit, style: const TextStyle(fontSize: 15)),
                              Text('${kg(c.quantite)}'
                                  '${c.prixUnitaire != null ? ' × ${fcfa(c.prixUnitaire!)}/kg' : ''}'
                                  '${c.prix != null ? ' = ${fcfa(c.prix!)}' : ''}',
                                  style: const TextStyle(color: Colors.grey, fontSize: 13)),
                              // Lot 12 : total avec la livraison, état du paiement, bouton « Payer »
                              if ((c.totalAPayer ?? 0) > 0) ...[
                                const SizedBox(height: 4),
                                Text('À payer : ${fcfa(c.totalAPayer!)} (dont livraison ${fcfa(c.fraisLivraison ?? 0)})',
                                    style: const TextStyle(fontWeight: FontWeight.w600)),
                                Text(etatsPaiement[c.statutPaiement] ?? c.statutPaiement,
                                    style: TextStyle(fontSize: 12, color: c.statutPaiement == 'litige' ? AppTheme.rouge : AppTheme.vertFonce)),
                                if (c.aPayer) Padding(padding: const EdgeInsets.only(top: 6), child: SizedBox(width: double.infinity, height: 48,
                                  child: ElevatedButton.icon(
                                    key: Key('payer-${c.id}'),
                                    icon: const Icon(Icons.payments_outlined),
                                    label: const Text('Payer', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                                    onPressed: () => _payer(c),
                                  ))),
                              ],

                              if (c.alerteLivraison)
                                Container(
                                  margin: const EdgeInsets.only(top: 8),
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                  decoration: BoxDecoration(color: Colors.red.shade100, borderRadius: BorderRadius.circular(6)),
                                  child: const Row(children: [
                                    Icon(Icons.warning_amber_outlined, color: AppTheme.rouge, size: 15),
                                    SizedBox(width: 6),
                                    Expanded(child: Text('Incident sur la livraison : l\'entrepôt vous tient informé',
                                        style: TextStyle(color: AppTheme.rouge, fontSize: 12, fontWeight: FontWeight.w600))),
                                  ]),
                                ),

                              if (c.livraison != null) ...[
                                const SizedBox(height: 6),
                                // Wrap : le bouton passe à la ligne sur un écran étroit
                                Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 4, children: [
                                  const Icon(Icons.local_shipping_outlined, size: 14, color: Colors.grey),
                                  StatusBadge(c.livraison!['statut'] ?? ''),
                                  if (c.livraison!['statut'] == 'en_cours') ...[
                                    const SizedBox(width: 4),
                                    TextButton.icon(
                                      icon: const Icon(Icons.map_outlined, size: 16),
                                      label: const Text('Suivre en direct'),
                                      // 40 px de haut : cible tactile confortable
                                      style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), minimumSize: const Size(0, 40)),
                                      onPressed: () => Navigator.push(context, MaterialPageRoute(
                                        builder: (_) => SuiviLivraisonScreen(livraisonId: c.livraison!['id']),
                                      )),
                                    ),
                                  ],
                                ]),
                              ],

                              if (c.codeReception != null)
                                Container(
                                  key: Key('code-${c.id}'),
                                  margin: const EdgeInsets.only(top: 8),
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                  decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(6)),
                                  child: Text('Code de réception à donner au transporteur : ${c.codeReception}',
                                      style: const TextStyle(fontWeight: FontWeight.bold)),
                                ),
                              if (c.prochaineEtape != null)
                                Padding(padding: const EdgeInsets.only(top: 6),
                                    child: Text('Étape suivante : ${c.prochaineEtape}', style: const TextStyle(fontSize: 12, color: Colors.grey))),
                              for (final i in c.incidents)
                                Padding(padding: const EdgeInsets.only(top: 4), child: Text(
                                  '${libelleIncident(i['type'] ?? '')} : ${i['statut'] == 'ouvert' ? 'en attente de l\'entrepôt' : (libelleDecision[i['decision']] ?? 'traité')}'
                                  '${i['motif_decision'] != null ? ' (${i['motif_decision']})' : ''}',
                                  style: const TextStyle(fontSize: 12))),

                              // Boutons donnés par le serveur
                              if (c.actions.isNotEmpty) ...[
                                const SizedBox(height: 10),
                                Wrap(spacing: 8, runSpacing: 8, children: [
                                  if (c.peut('confirmer_reception'))
                                    ElevatedButton.icon(
                                      key: Key('reception-${c.id}'),
                                      icon: const Icon(Icons.check_circle_outline, size: 16),
                                      label: Text(libelleActionCommande['confirmer_reception']!),
                                      onPressed: () => _confirmerReception(c),
                                    ),
                                  if (c.peut('declarer_anomalie'))
                                    OutlinedButton.icon(
                                      key: Key('anomalie-${c.id}'),
                                      icon: const Icon(Icons.report_problem_outlined, size: 16),
                                      label: Text(libelleActionCommande['declarer_anomalie']!),
                                      onPressed: () => _anomalie(c),
                                    ),
                                  if (c.peut('modifier'))
                                    OutlinedButton.icon(
                                      icon: const Icon(Icons.edit_outlined, size: 16),
                                      label: const Text('Modifier'),
                                      onPressed: () => _modifier(c),
                                    ),
                                  if (c.peut('annuler'))
                                    OutlinedButton.icon(
                                      style: OutlinedButton.styleFrom(foregroundColor: AppTheme.rouge, side: const BorderSide(color: AppTheme.rouge)),
                                      icon: const Icon(Icons.cancel_outlined, size: 16),
                                      label: const Text('Annuler'),
                                      onPressed: () => _annuler(c),
                                    ),
                                  if (c.peut('demander_annulation'))
                                    OutlinedButton(
                                      key: Key('demande-annulation-${c.id}'),
                                      onPressed: () => _demanderAnnulation(c),
                                      child: Text(libelleActionCommande['demander_annulation']!),
                                    ),
                                ]),
                              ],
                            ]),
                          ),
                        );
                      },
                    ),
                  ),
  );
}
