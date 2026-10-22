import 'package:flutter/material.dart';
import '../../models/livraison.dart';
import '../../services/api_service.dart';
import '../../services/suivi_gps_service.dart';
import '../../utils/livraison.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/carte_depot.dart' show confirmerAction, demanderMotifAction;
import '../../widgets/declarer_incident.dart';
import '../../widgets/bouton_conduite.dart';
import '../../widgets/status_badge.dart';

/// Mission de livraison : les boutons viennent des actions renvoyées par le
/// serveur (accepter, charger, partir, arriver, remettre avec le code, incident).
class DetailLivraisonScreen extends StatefulWidget {
  final Livraison livraison;
  const DetailLivraisonScreen({super.key, required this.livraison});
  @override
  State<DetailLivraisonScreen> createState() => _DetailLivraisonScreenState();
}

class _DetailLivraisonScreenState extends State<DetailLivraisonScreen> {
  static const _boutons = ['accepter_mission', 'refuser_mission', 'charger', 'partir', 'arriver'];

  late Livraison _l;
  bool _loading = false;
  bool _modifiee = false;
  String? _erreur;
  final _code = TextEditingController();
  final _suivi = SuiviGpsService.instance;

  @override
  void initState() {
    super.initState();
    _l = widget.livraison;
    // Livraison en route : le partage continue (ou reprend), même si l'écran est quitté.
    if (_l.statut == 'en_cours') _synchroniserSuivi();
  }

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  /// Partage de position pendant le trajet seulement ; il continue écran quitté ou téléphone verrouillé.
  Future<void> _synchroniserSuivi() async {
    if (_l.statut == 'en_cours') {
      final ok = await _suivi.demarrer((type: 'livraison', id: _l.id, numero: _l.numeroLivraison));
      if (!ok && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Position GPS indisponible : activez la localisation pour partager votre trajet.'), backgroundColor: AppTheme.orange));
      }
    } else if (_suivi.suit('livraison', _l.id)) {
      await _suivi.arreter();
    }
    if (mounted) setState(() {});
  }

  /// Relit la livraison : statut, actions et incidents à jour.
  Future<void> _actualiser() async {
    try {
      final res = await ApiService.getLivraisons();
      final trouvee = (res['livraisons'] as List).cast<Map<String, dynamic>>().where((e) => e['id'] == _l.id);
      if (!mounted) return;
      if (trouvee.isEmpty) { Navigator.pop(context, true); return; } // mission retirée
      setState(() => _l = Livraison.fromJson(trouvee.first));
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _executer(Future<dynamic> Function() appel, String succes) async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await appel();
      _modifiee = true;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text((res is Map ? res['message'] : null) ?? succes), backgroundColor: AppTheme.vert));
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
    await _actualiser();
    if (!mounted) return;
    setState(() => _loading = false);
    await _synchroniserSuivi();
  }

  Future<void> _agir(String action) async {
    if (action == 'accepter_mission') {
      if (!await confirmerAction(context, 'Accepter la livraison ${_l.numeroLivraison} : ${_l.commande?['produit'] ?? ''} à charger à l\'entrepôt et livrer à ${_l.destination ?? 'l\'acheteur'} ?')) return;
      return _executer(() => ApiService.accepterLivraison(_l.id), 'Mission acceptée.');
    }
    if (action == 'refuser_mission') {
      final motif = await demanderMotifAction(context, 'Refuser la livraison ${_l.numeroLivraison} ?',
          'Elle sera rendue à l\'entrepôt, qui affectera un autre transporteur.', obligatoire: true);
      if (motif == null) return;
      return _executer(() => ApiService.refuserLivraison(_l.id, motif), 'Mission refusée.');
    }
    if (!mounted || !await confirmerAction(context, confirmationEtapeLivraison[action] ?? 'Confirmer ?')) return;
    await _executer(() => ApiService.etapeLivraison(_l.id, etapeLivraison[action]!), 'Étape enregistrée.');
  }

  Future<void> _remettre() async {
    final code = _code.text.trim();
    if (!RegExp(r'^\d{4}$').hasMatch(code)) { setState(() => _erreur = 'Le code de réception comporte 4 chiffres.'); return; }
    await _executer(() => ApiService.remettreLivraison(_l.id, code), 'Livraison remise.');
    if (_l.statut == 'livree') _code.clear();
  }

  Future<void> _incident() async {
    if (await declarerIncident(context, sujetType: 'livraison', sujetId: _l.id, numero: _l.numeroLivraison)) {
      _modifiee = true;
      await _actualiser();
    }
  }

  Widget _infoRow(String label, String? value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(width: 110, child: Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13))),
      Expanded(child: Text(value ?? '—', style: const TextStyle(fontWeight: FontWeight.w500))),
    ]),
  );

  String? _contact(Map<String, dynamic>? u) => u == null ? null
      : '${u['prenom'] ?? ''} ${u['nom'] ?? ''}${u['telephone'] != null ? ' · ${u['telephone']}' : ''}'.trim();

  @override
  Widget build(BuildContext context) {
    final commande = _l.commande;
    final entrepot = _l.entrepot;
    final boutons = _boutons.where(_l.peut).toList();

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (aFait, _) { if (!aFait) Navigator.pop(context, _modifiee); },
      child: Scaffold(
        appBar: AppBar(title: Text(_l.numeroLivraison), actions: [
          if (_loading) const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))),
        ]),
        body: RefreshIndicator(
          onRefresh: _actualiser,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Card(child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    const Expanded(child: Text('Statut actuel', style: TextStyle(fontWeight: FontWeight.bold))),
                    StatusBadge(_l.statut),
                  ]),
                  if (_l.prochaineEtape != null) Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text('Étape suivante : ${_l.prochaineEtape}', style: const TextStyle(fontSize: 13, color: Colors.grey)),
                  ),
                ]),
              )),

              for (final i in _l.incidents)
                _bandeau(Icons.warning_amber_outlined, 'Incident : ${libelleIncident(i['type'] ?? '')}. L\'entrepôt décide de la suite.', Colors.red.shade50, AppTheme.rouge),
              if (_l.statut == 'en_cours')
                _suivi.suit('livraison', _l.id)
                    ? _bandeau(Icons.gps_fixed, 'Position partagée avec l\'acheteur et l\'entrepôt, même téléphone verrouillé (notification « Suivi de livraison actif »).', AppTheme.vertClair, AppTheme.vertFonce)
                    : _bandeau(Icons.gps_off, 'Position non partagée : activez la localisation puis rouvrez cette mission.', Colors.orange.shade50, AppTheme.orange),
              if (_erreur != null)
                _bandeau(Icons.error_outline, _erreur!, Colors.red.shade50, AppTheme.rouge),

              Card(child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Détails', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  const Divider(),
                  if (commande != null) ...[
                    _infoRow('Commande', commande['numero_commande']),
                    _infoRow('Produit', '${commande['produit'] ?? ''} — ${kg(nombre(commande['quantite']) ?? 0)}'),
                  ],
                  _infoRow('Charger à', entrepot?['nom_entrepot'] ?? _l.origine),
                  if (entrepot?['localisation'] != null) _infoRow('', entrepot!['localisation']),
                  _infoRow('Entrepôt', (entrepot?['utilisateur'] as Map?)?['telephone']),
                  _infoRow('Livrer à', _contact(_l.acheteur)),
                  _infoRow('Adresse', _l.destination),
                ]),
              )),
              const SizedBox(height: 8),
              // Lot 9 : guidage dans l'application (chemin, consignes, gros bouton d'étape)
              if (['prise_en_charge', 'chargee', 'en_cours'].contains(_l.statut)) Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SizedBox(width: double.infinity, child: BoutonConduite(
                  type: 'livraison', id: _l.id, numero: _l.numeroLivraison,
                  libelle: _l.statut == 'prise_en_charge' ? 'Conduire jusqu\'à l\'entrepôt' : 'Conduire jusqu\'à l\'acheteur',
                  auRetour: () async { _modifiee = true; await _actualiser(); await _synchroniserSuivi(); },
                )),
              ),

              if (_l.peut('confirmer_par_code')) Card(child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Remise à l\'acheteur', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 4),
                  const Text('Demandez à l\'acheteur son code de réception (4 chiffres), ou laissez-le confirmer depuis son espace.', style: TextStyle(fontSize: 13)),
                  TextField(
                    key: const Key('code-reception'),
                    controller: _code,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    decoration: const InputDecoration(labelText: 'Code de réception'),
                  ),
                  SizedBox(width: double.infinity, child: ElevatedButton.icon(
                    key: const Key('remettre'),
                    icon: const Icon(Icons.check_circle_outline),
                    label: Text(libelleActionLivraison['confirmer_par_code']!),
                    onPressed: _loading ? null : _remettre,
                  )),
                ]),
              )),

              for (final a in boutons) Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SizedBox(width: double.infinity, child: a == 'refuser_mission'
                    ? OutlinedButton(key: Key('livraison-$a'), onPressed: _loading ? null : () => _agir(a), child: Text(libelleActionLivraison[a]!))
                    : ElevatedButton(key: Key('livraison-$a'), onPressed: _loading ? null : () => _agir(a), child: Text(libelleActionLivraison[a]!))),
              ),
              if (_l.peut('declarer_incident'))
                SizedBox(width: double.infinity, child: OutlinedButton.icon(
                  key: const Key('livraison-declarer_incident'),
                  style: OutlinedButton.styleFrom(foregroundColor: AppTheme.rouge, side: const BorderSide(color: AppTheme.rouge)),
                  icon: const Icon(Icons.warning_amber_outlined),
                  label: Text(libelleActionLivraison['declarer_incident']!),
                  onPressed: _loading ? null : _incident,
                )),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _bandeau(IconData icone, String texte, Color fond, Color couleur) => Container(
    margin: const EdgeInsets.only(bottom: 8),
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
    decoration: BoxDecoration(color: fond, borderRadius: BorderRadius.circular(8)),
    child: Row(children: [
      Icon(icone, size: 18, color: couleur),
      const SizedBox(width: 8),
      Expanded(child: Text(texte, style: TextStyle(color: couleur, fontSize: 13))),
    ]),
  );
}
