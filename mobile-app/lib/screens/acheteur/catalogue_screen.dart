import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../services/position_appareil.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/choix_position.dart';
import '../../widgets/info_card.dart';
import 'formulaire_commande.dart';
import '../paiement/paiement_screen.dart';
import 'mes_commandes_screen.dart';
import '../shared/tracabilite_screen.dart';
import '../shared/profil_screen.dart';

class CatalogueScreen extends StatefulWidget {
  const CatalogueScreen({super.key});
  @override
  State<CatalogueScreen> createState() => _CatalogueScreenState();
}

class _CatalogueScreenState extends State<CatalogueScreen> {
  List<Map<String, dynamic>> _produits = [];
  bool _loading = true;
  String? _erreur;

  /// Lot 8 : lieu de livraison pris automatiquement (adresse habituelle, puis position
  /// du téléphone), modifiable sur la carte. Joint à chaque commande.
  LatLng? _lieu;
  String _origineLieu = 'aucun';

  @override
  void initState() {
    super.initState();
    _charger();
    _lieu = PositionAppareil.lieuHabituelConnecte(context);
    if (_lieu != null) _origineLieu = 'habituel';
    PositionAppareil.lire().then((p) {
      if (p != null && mounted && _origineLieu != 'carte') setState(() { _lieu = p; _origineLieu = 'telephone'; });
    });
  }

  Map<String, dynamic> get _champsLieu =>
      _lieu == null ? {} : {'livraison_latitude': _lieu!.latitude, 'livraison_longitude': _lieu!.longitude};

  Future<void> _modifierLieu() async {
    LatLng? choisi = _lieu;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (c) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          ChoixPosition(key: const Key('lieu-livraison-carte'), libelle: 'Où voulez-vous être livré ?', initiale: _lieu, auto: true, onChange: (p) => choisi = p),
          const SizedBox(height: 12),
          ElevatedButton(key: const Key('lieu-livraison-valider'), onPressed: () => Navigator.pop(c, true), child: const Text('Livrer ici')),
        ]),
      ),
    );
    if (ok == true && choisi != null && mounted) setState(() { _lieu = choisi; _origineLieu = 'carte'; });
  }

  Widget _bandeauLieu() => Material(
        color: AppTheme.vertClair,
        child: ListTile(
          key: const Key('lieu-livraison'),
          dense: true,
          leading: const Icon(Icons.location_on, color: AppTheme.vertFonce),
          title: Text(switch (_origineLieu) {
            'telephone' => 'Livraison à votre position actuelle',
            'habituel' => 'Livraison à votre adresse habituelle',
            'carte' => 'Livraison au lieu choisi sur la carte',
            _ => 'Lieu de livraison à placer sur la carte',
          }),
          trailing: TextButton(onPressed: _modifierLieu, child: const Text('Modifier')),
        ),
      );

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getCatalogue();
      setState(() {
        _produits = List<Map<String, dynamic>>.from(res['catalogue']);
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  /// Panier : plusieurs produits commandés en une fois, tout ou rien.
  final List<({int stockId, String produit, double quantite, double prix})> _panier = [];
  String _clePanier = ApiService.nouvelleCleIdempotence();

  double get _totalPanier => (_panier.fold<double>(0, (t, l) => t + l.quantite * l.prix) * 100).round() / 100;

  void _ajouter(Map<String, dynamic> p, double quantite) => setState(() {
    _panier.removeWhere((l) => l.stockId == p['stock_id']);
    _panier.add((stockId: p['stock_id'] as int, produit: p['produit'] as String? ?? '', quantite: quantite, prix: nombre(p['prix_unitaire']) ?? 0));
    _clePanier = ApiService.nouvelleCleIdempotence();
  });

  Future<void> _ouvrirPanier() async {
    final messager = ScaffoldMessenger.of(context);
    final moyen = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (c) => StatefulBuilder(builder: (c, setSheet) => SafeArea(child: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Mon panier', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          for (final l in _panier) ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text('${l.produit} — ${kg(l.quantite)}'),
            subtitle: Text('${fcfa(l.prix)} / kg = ${fcfa((l.quantite * l.prix * 100).round() / 100)}'),
            trailing: IconButton(icon: const Icon(Icons.delete_outline), tooltip: 'Retirer', onPressed: () {
              setState(() { _panier.remove(l); _clePanier = ApiService.nouvelleCleIdempotence(); });
              _panier.isEmpty ? Navigator.pop(c) : setSheet(() {});
            }),
          ),
          Text('Total : ${fcfa(_totalPanier)}', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const Text('Tout ou rien : si un produit n\'est plus disponible en quantité suffisante, aucune commande n\'est passée.',
              style: TextStyle(fontSize: 12, color: Colors.grey)),
          const SizedBox(height: 12),
          const Text('Livraison ajoutée au paiement selon la distance.', style: TextStyle(fontSize: 12, color: Colors.grey)),
          const SizedBox(height: 8),
          // Lot 13 : le panier est commandé et payé d'un seul appui
          BoutonsPaiement(onChoix: (m) => Navigator.pop(c, m), libelleWave: 'Payer le panier avec Wave'),
        ]),
      ))),
    );
    if (moyen == null || _panier.isEmpty) return;
    try {
      final res = await ApiService.passerCommande({
        'lignes': [for (final l in _panier) {'stock_id': l.stockId, 'quantite': l.quantite}],
        ..._champsLieu,
        'moyen': moyen,
      }, cleIdempotence: _clePanier);
      setState(() { _panier.clear(); _clePanier = ApiService.nouvelleCleIdempotence(); });
      if (!mounted) return;
      final message = await apresCommande(context, res is Map ? res : const {});
      if (message != null) messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
      _charger();
    } on ApiException catch (e) {
      messager.showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.rouge));
    }
  }

  Future<void> _commander(Map<String, dynamic> produit) async {
    final res = await ouvrirFormulaireCommande(context, produit, surPanier: (q) => _ajouter(produit, q), lieu: _champsLieu);
    if (res == null || !mounted) return;
    final messager = ScaffoldMessenger.of(context);
    final message = await apresCommande(context, res);
    if (message != null) messager.showSnackBar(SnackBar(content: Text(message), backgroundColor: AppTheme.vert));
    _charger();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.read<AuthProvider>();
    return Scaffold(
      floatingActionButton: _panier.isEmpty ? null : FloatingActionButton.extended(
        key: const Key('panier'),
        onPressed: _ouvrirPanier,
        icon: const Icon(Icons.shopping_cart_outlined),
        label: Text('Panier (${_panier.length}) · ${fcfa(_totalPanier)}'),
      ),
      appBar: AppBar(
        title: Text('Catalogue — ${auth.utilisateur?.prenom ?? ''}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.receipt_long_outlined),
            tooltip: 'Mes commandes',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const MesCommandesScreen())),
          ),
          IconButton(
            icon: const Icon(Icons.qr_code_scanner_outlined),
            tooltip: 'Traçabilité',
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const TracabiliteScreen())),
          ),
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert),
            onSelected: (v) {
              if (v == 'profil') Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfilScreen()));
              if (v == 'logout') { auth.logout(); Navigator.pushReplacementNamed(context, '/login'); }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'profil', child: ListTile(leading: Icon(Icons.person_outline), title: Text('Mon profil'), dense: true)),
              const PopupMenuItem(value: 'logout', child: ListTile(leading: Icon(Icons.logout), title: Text('Déconnexion'), dense: true)),
            ],
          ),
        ],
      ),
      body: Column(children: [_bandeauLieu(), Expanded(child: _loading
          ? const Center(child: CircularProgressIndicator())
          : _erreur != null
              ? ErrorState(message: _erreur!, onRetry: _charger)
              : _produits.isEmpty
                  ? const EmptyState(message: 'Aucun produit disponible.', icon: Icons.storefront_outlined)
                  : RefreshIndicator(
                      onRefresh: _charger,
                      child: ListView.builder(
                        padding: const EdgeInsets.only(bottom: 80),
                        itemCount: _produits.length,
                        itemBuilder: (_, i) {
                          final p = _produits[i];
                          final entrepot  = p['entrepot']  as Map?;
                          final production = p['production'] as Map?;
                          return Card(
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                                  Expanded(child: Text(p['produit'] ?? '—', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
                                  const SizedBox(width: 8),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                    decoration: BoxDecoration(color: AppTheme.vertClair, borderRadius: BorderRadius.circular(12)),
                                    child: Text('${kg(nombre(p['quantite_disponible']) ?? nombre(p['quantite']) ?? 0)} dispo.',
                                        style: const TextStyle(color: AppTheme.vertFonce, fontWeight: FontWeight.bold)),
                                  ),
                                ]),
                                if (nombre(p['prix_unitaire']) != null) ...[
                                  const SizedBox(height: 4),
                                  Text('${fcfa(nombre(p['prix_unitaire'])!)} / kg',
                                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                                ],
                                const SizedBox(height: 8),
                                if (entrepot != null) ...[
                                  Row(children: [
                                    const Icon(Icons.warehouse_outlined, size: 14, color: Colors.grey),
                                    const SizedBox(width: 4),
                                    Expanded(child: Text(entrepot['nom_entrepot'] ?? '', style: const TextStyle(fontSize: 13))),
                                  ]),
                                  Row(children: [
                                    const Icon(Icons.location_on_outlined, size: 14, color: Colors.grey),
                                    const SizedBox(width: 4),
                                    Expanded(child: Text(entrepot['localisation'] ?? '', style: const TextStyle(fontSize: 12, color: Colors.grey))),
                                  ]),
                                ],
                                if (production != null) ...[
                                  const SizedBox(height: 4),
                                  Row(children: [
                                    const Icon(Icons.qr_code, size: 14, color: AppTheme.vert),
                                    const SizedBox(width: 4),
                                    Flexible(child: Text(production['code_tracabilite'] ?? '', style: const TextStyle(fontSize: 12, color: AppTheme.vert))),
                                  ]),
                                ],
                                const SizedBox(height: 10),
                                SizedBox(
                                  width: double.infinity,
                                  child: ElevatedButton.icon(
                                    icon: const Icon(Icons.add_shopping_cart),
                                    label: const Text('Commander'),
                                    onPressed: () => _commander(p),
                                  ),
                                ),
                              ]),
                            ),
                          );
                        },
                      ),
                    )),
      ]),
    );
  }
}
