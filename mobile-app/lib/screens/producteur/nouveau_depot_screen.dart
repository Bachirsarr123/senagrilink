import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';
import '../../models/production.dart';
import '../../services/api_service.dart';
import '../../services/position_appareil.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/choix_position.dart';
import '../../widgets/info_card.dart';

/// Demande de dépôt : entrepôt qui a la place, récolte (lot), mode de transport.
/// Lot 9 : tout est prérempli (dernière récolte, entrepôt le plus proche,
/// transporteur, position) ; le producteur vérifie et envoie.
/// Produit et quantité sont repris de la récolte (pas de ressaisie). La clé
/// d'idempotence est réutilisée si le producteur réessaie après une erreur.
class NouveauDepotScreen extends StatefulWidget {
  const NouveauDepotScreen({super.key});
  @override
  State<NouveauDepotScreen> createState() => _NouveauDepotScreenState();
}

class _NouveauDepotScreenState extends State<NouveauDepotScreen> {
  final _form = GlobalKey<FormState>();
  final _quantiteCtl = TextEditingController();
  final _cle = ApiService.nouvelleCleIdempotence();
  List<Map<String, dynamic>> _entrepots = [];
  List<Production> _recoltes = [];
  int? _entrepotId;
  Production? _recolte;
  String? _mode;
  /// Position du téléphone (prise d'elle-même) et lieu de prise en charge retenu.
  LatLng? _positionTelephone;
  LatLng? _collecte;
  bool _chargement = true;
  bool _envoi = false;
  String? _erreur;
  String? _erreurChargement;

  @override
  void initState() { super.initState(); _charger(); }

  @override
  void dispose() { _quantiteCtl.dispose(); super.dispose(); }

  Future<void> _charger() async {
    setState(() { _chargement = true; _erreurChargement = null; });
    try {
      final productions = ApiService.getProductions();
      _positionTelephone ??= await PositionAppareil.lire();
      final entrepots = await ApiService.getEntrepotsDisponibles(latitude: _positionTelephone?.latitude, longitude: _positionTelephone?.longitude);
      final recoltes = await productions;
      if (!mounted) return;
      final habituel = PositionAppareil.lieuHabituelConnecte(context);
      setState(() {
        _collecte ??= _positionTelephone ?? habituel;
        _entrepots = ((entrepots['entrepots'] as List?) ?? []).cast<Map<String, dynamic>>();
        _recoltes = ((recoltes['productions'] as List?) ?? [])
            .map((e) => Production.fromJson(e as Map<String, dynamic>))
            .where((p) => p.statut != 'annulee')
            .toList();
        // Lot 9 : tout est prérempli — entrepôt le plus proche, transporteur ; il ne reste qu'à vérifier et envoyer
        _entrepotId ??= _entrepots.isNotEmpty ? _entrepots.first['id'] as int : null;
        _mode ??= 'transporteur';
        _chargement = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreurChargement = e.message; _chargement = false; });
      return;
    }
    // Récolte la plus récente, quantité reprise
    if (_recolte == null && _recoltes.isNotEmpty) _choisirRecolte(_recoltes.reduce((a, b) => a.id > b.id ? a : b));
  }

  void _choisirRecolte(Production? p) {
    setState(() => _recolte = p);
    final q = p?.quantiteReelle ?? p?.quantiteEstimee;
    if (q != null) _quantiteCtl.text = nombreLisible(q);
  }

  Future<void> _envoyer() async {
    if (!_form.currentState!.validate()) return;
    setState(() { _envoi = true; _erreur = null; });
    final aujourdhui = DateTime.now();
    try {
      await ApiService.demanderDepot({
        'entrepot_id': _entrepotId,
        'production_id': _recolte!.id,
        'mode_transport': _mode,
        if (_mode == 'transporteur' && _collecte != null) ...{
          'collecte_latitude': _collecte!.latitude,
          'collecte_longitude': _collecte!.longitude,
        },
        'quantite_reservee': nombre(_quantiteCtl.text.trim().replaceAll(',', '.')),
        'date_debut': '${aujourdhui.year}-${aujourdhui.month.toString().padLeft(2, '0')}-${aujourdhui.day.toString().padLeft(2, '0')}',
      }, cleIdempotence: _cle);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) setState(() { _erreur = e.message; _envoi = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Déposer une récolte')),
    body: _chargement
        ? const Center(child: CircularProgressIndicator())
        : _erreurChargement != null
            ? ErrorState(message: _erreurChargement!, onRetry: _charger)
            : SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Form(
                  key: _form,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (_recoltes.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(bottom: 12),
                        child: Text('Enregistrez d\'abord votre récolte : chaque dépôt correspond à un lot.',
                            style: TextStyle(color: AppTheme.rouge)),
                      ),
                    DropdownButtonFormField<Production>(
                      key: const Key('depot-recolte'),
                      initialValue: _recolte,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Récolte à déposer *'),
                      items: [
                        for (final p in _recoltes)
                          DropdownMenuItem(value: p, child: Text('${p.codeTracabilite} — ${p.typeCulture ?? ''}', overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: _choisirRecolte,
                      validator: (v) => v == null ? 'Choisissez la récolte.' : null,
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<int>(
                      key: const Key('depot-entrepot'),
                      initialValue: _entrepotId,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Entrepôt *'),
                      items: [
                        for (final e in _entrepots)
                          DropdownMenuItem(
                            value: e['id'] as int,
                            child: Text(
                              '${e['nom_entrepot'] ?? ''} — ${kg(nombre(e['capacite_disponible']) ?? 0)} libres'
                              '${e['distance_km'] != null ? ' · à ${nombreLisible(nombre(e['distance_km'])!)} km' : ''}',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                      onChanged: (v) => setState(() => _entrepotId = v),
                      validator: (v) => v == null ? 'Choisissez un entrepôt qui a de la place.' : null,
                    ),
                    if (_entrepots.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 6),
                        child: Text('Aucun entrepôt n\'a de place pour le moment.', style: TextStyle(color: Colors.grey)),
                      ),
                    const SizedBox(height: 14),
                    TextFormField(
                      key: const Key('depot-quantite'),
                      controller: _quantiteCtl,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(labelText: 'Quantité à déposer (kg) *', suffixText: 'kg'),
                      validator: (v) => (nombre(v?.trim().replaceAll(',', '.')) ?? 0) < 0.01 ? 'Indiquez la quantité.' : null,
                    ),
                    const SizedBox(height: 16),
                    const Text('Qui transporte la marchandise ? *', style: TextStyle(fontWeight: FontWeight.w600)),
                    FormField<String>(
                      validator: (_) => _mode == null ? 'Choisissez qui transporte la marchandise.' : null,
                      builder: (champ) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        RadioGroup<String>(
                          groupValue: _mode,
                          onChanged: (v) { setState(() => _mode = v); champ.didChange(v); },
                          child: const Column(children: [
                            RadioListTile<String>(
                              key: Key('mode-transporteur'),
                              value: 'transporteur',
                              title: Text('Un transporteur vient la chercher'),
                              subtitle: Text('L\'entrepôt affecte un transporteur.'),
                            ),
                            RadioListTile<String>(
                              key: Key('mode-producteur'),
                              value: 'producteur',
                              title: Text('Je la livre moi-même'),
                              subtitle: Text('Vous indiquerez votre départ puis votre arrivée.'),
                            ),
                          ]),
                        ),
                        if (champ.hasError) Text(champ.errorText!, style: const TextStyle(color: AppTheme.rouge, fontSize: 12)),
                      ]),
                    ),
                    if (_mode == 'transporteur') ...[
                      const SizedBox(height: 8),
                      // Lot 8 : lieu pris automatiquement, le transporteur y sera guidé
                      ChoixPosition(
                        key: const Key('depot-lieu-collecte'),
                        libelle: 'Où le transporteur vient-il chercher la marchandise ?',
                        initiale: _collecte,
                        auto: true,
                        onChange: (p) => _collecte = p,
                      ),
                    ],
                    if (_erreur != null) ...[
                      const SizedBox(height: 8),
                      Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
                    ],
                    const SizedBox(height: 20),
                    ElevatedButton(
                      key: const Key('depot-envoyer'),
                      onPressed: _envoi ? null : _envoyer,
                      child: Text(_envoi ? 'Envoi…' : 'Envoyer la demande'),
                    ),
                  ]),
                ),
              ),
  );
}
