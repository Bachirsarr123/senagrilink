import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../widgets/status_badge.dart';

/// Agriculteurs de l'entrepôt (lot 11) : recherche par nom, téléphone ou région,
/// puis une fiche qui regroupe ses dépôts, son stock ici et ses ventes.
class AgriculteursScreen extends StatefulWidget {
  const AgriculteursScreen({super.key});
  @override
  State<AgriculteursScreen> createState() => _AgriculteursScreenState();
}

class _AgriculteursScreenState extends State<AgriculteursScreen> {
  List<Map<String, dynamic>> _liste = [];
  bool _chargement = true;
  Timer? _attente;

  @override
  void initState() { super.initState(); _charger(''); }

  @override
  void dispose() { _attente?.cancel(); super.dispose(); }

  Future<void> _charger(String q) async {
    setState(() => _chargement = true);
    try {
      final res = await ApiService.getAgriculteurs(q.trim());
      if (mounted) setState(() { _liste = ((res['producteurs'] as List?) ?? []).cast<Map<String, dynamic>>(); _chargement = false; });
    } on ApiException {
      if (mounted) setState(() => _chargement = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Agriculteurs')),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              key: const Key('recherche-agriculteur'),
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Nom, téléphone ou région…'),
              onChanged: (q) { _attente?.cancel(); _attente = Timer(const Duration(milliseconds: 300), () => _charger(q)); },
            ),
          ),
          Expanded(child: _chargement && _liste.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : _liste.isEmpty
                  ? const Center(child: Text('Aucun agriculteur trouvé.', style: TextStyle(color: Colors.grey)))
                  : ListView(children: [
                      for (final p in _liste) ListTile(
                        leading: const CircleAvatar(child: Icon(Icons.agriculture)),
                        title: Text(p['nom'] as String? ?? ''),
                        subtitle: Text('${p['telephone'] ?? ''}${p['region'] != null ? ' · ${p['region']}' : ''}\n'
                            '${kg(nombre(p['stock_kg']) ?? 0)} en stock · ${p['depots_en_cours']} dépôt(s) en cours'),
                        isThreeLine: true,
                        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => FicheAgriculteurScreen(id: p['id'] as int))),
                      ),
                    ])),
        ]),
      );
}

class FicheAgriculteurScreen extends StatefulWidget {
  final int id;
  const FicheAgriculteurScreen({super.key, required this.id});
  @override
  State<FicheAgriculteurScreen> createState() => _FicheAgriculteurScreenState();
}

class _FicheAgriculteurScreenState extends State<FicheAgriculteurScreen> {
  Map<String, dynamic>? _f;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    ApiService.getAgriculteur(widget.id).then((r) { if (mounted) setState(() => _f = (r as Map).cast<String, dynamic>()); })
        .catchError((Object e) { if (mounted) setState(() => _erreur = e is ApiException ? e.message : 'Fiche indisponible.'); });
  }

  List<Map> _l(String cle) => ((_f?[cle] as List?) ?? const []).cast<Map>();

  @override
  Widget build(BuildContext context) {
    final p = (_f?['producteur'] as Map?)?.cast<String, dynamic>();
    Widget titre(String t) => Padding(padding: const EdgeInsets.fromLTRB(4, 16, 4, 4), child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)));
    return Scaffold(
      appBar: AppBar(title: Text(p?['nom'] as String? ?? 'Agriculteur')),
      body: _f == null
          ? Center(child: _erreur != null ? Text(_erreur!) : const CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(12), children: [
              Card(child: ListTile(
                leading: const Icon(Icons.phone),
                title: Text('${p?['telephone'] ?? ''}'),
                subtitle: Text([p?['region'], p?['adresse']].whereType<String>().join(' · ')),
                trailing: const Text('Appeler'),
                onTap: () => launchUrl(Uri.parse('tel:${p?['telephone'] ?? ''}')),
              )),
              titre('Stock ici'),
              if (_l('stocks').isEmpty) const Text('Aucun stock.', style: TextStyle(color: Colors.grey)),
              for (final s in _l('stocks')) ListTile(dense: true, title: Text('${s['produit']} — ${kg(nombre(s['quantite_disponible']) ?? 0)} disponibles'),
                  subtitle: Text('Lot ${s['lot'] ?? ''}')),
              titre('Dépôts'),
              for (final d in _l('depots')) ListTile(dense: true, title: Text('${d['numero_reservation']} · ${d['produit']}, ${kg(nombre(d['quantite_reservee']) ?? 0)}'),
                  trailing: StatusBadge(d['statut'] as String? ?? '')),
              titre('Ventes'),
              if (_l('ventes').isEmpty) const Text('Aucune vente.', style: TextStyle(color: Colors.grey)),
              for (final v in _l('ventes')) ListTile(dense: true, title: Text('${v['numero_commande']} · ${kg(nombre(v['quantite']) ?? 0)} · ${fcfa(nombre(v['prix']) ?? 0)}'),
                  trailing: StatusBadge(v['statut'] as String? ?? '')),
            ]),
    );
  }
}
