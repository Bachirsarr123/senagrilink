import 'dart:async';

import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';
import '../paiement/paiement_screen.dart';

/// Passer une commande sur un produit du catalogue (P-09 : absent du mobile).
///
/// Mêmes champs et mêmes contrôles que le site web : quantité obligatoire
/// (≥ 0,01 kg, au plus le disponible affiché). Le prix au kilo est celui fixé
/// par le producteur : l'acheteur ne le saisit pas, il voit le total. Le
/// serveur revérifie la quantité disponible et fige le prix. La clé d'idempotence est créée à
/// l'ouverture et réutilisée si l'acheteur réessaie après une erreur : une
/// commande déjà enregistrée n'est jamais créée deux fois.
///
/// Lot 13 : commander et payer d'un seul appui (Wave, Orange Money ou espèces).
/// Renvoie la réponse du serveur (commande et paiement), ou null si l'acheteur renonce.
/// Avec [surPanier], un bouton ajoute la quantité au panier au lieu de commander.
Future<Map?> ouvrirFormulaireCommande(BuildContext context, Map<String, dynamic> produit, {ValueChanged<double>? surPanier, Map<String, dynamic> lieu = const {}}) =>
    showModalBottomSheet<Map>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FormulaireCommande(produit: produit, surPanier: surPanier, lieu: lieu),
    );

class FormulaireCommande extends StatefulWidget {
  final Map<String, dynamic> produit;
  final ValueChanged<double>? surPanier;
  /// Lieu de livraison (lot 8) joint à la commande.
  final Map<String, dynamic> lieu;
  const FormulaireCommande({super.key, required this.produit, this.surPanier, this.lieu = const {}});
  @override
  State<FormulaireCommande> createState() => _FormulaireCommandeState();
}

class _FormulaireCommandeState extends State<FormulaireCommande> {
  final _form = GlobalKey<FormState>();
  final _quantiteCtl = TextEditingController();
  final _cle = ApiService.nouvelleCleIdempotence();
  bool _envoi = false;
  String? _erreur;

  double get _disponible =>
      nombre(widget.produit['quantite_disponible']) ?? nombre(widget.produit['quantite']) ?? 0;

  double? get _prixUnitaire => nombre(widget.produit['prix_unitaire']);

  double? get _quantite => nombre(_quantiteCtl.text.replaceAll(',', '.'));

  /// Lot 12 : total à payer (marchandise + livraison), recalculé par le serveur.
  Map<String, dynamic>? _devis;
  Timer? _attenteDevis;

  @override
  void initState() {
    super.initState();
    _quantiteCtl.addListener(() {
      setState(() {});
      _attenteDevis?.cancel();
      _attenteDevis = Timer(const Duration(milliseconds: 400), _calculerDevis);
    });
  }

  Future<void> _calculerDevis() async {
    final q = _quantite;
    if (q == null || q <= 0 || q > _disponible) { if (mounted) setState(() => _devis = null); return; }
    try {
      final r = await ApiService.getDevis(widget.produit['stock_id'] as int, q,
          latitude: nombre(widget.lieu['livraison_latitude']), longitude: nombre(widget.lieu['livraison_longitude']));
      final d = r is Map ? r['devis'] : null;
      if (mounted) setState(() => _devis = d is Map ? d.cast<String, dynamic>() : null);
    } catch (_) {
      if (mounted) setState(() => _devis = null); // le total sans livraison reste affiché
    }
  }

  @override
  void dispose() {
    _quantiteCtl.dispose();
    _attenteDevis?.cancel();
    super.dispose();
  }

  double get _pas => _disponible >= 50 ? 10 : 1;

  void _ajuster(double delta) {
    final q = ((_quantite ?? 0) + delta).clamp(0, _disponible).toDouble();
    _quantiteCtl.text = q <= 0 ? '' : nombreLisible(q);
  }

  String? _validerQuantite(String? v) {
    final q = nombre(v?.replaceAll(',', '.'));
    if (q == null) return 'Indiquez la quantité souhaitée.';
    if (q < 0.01) return 'La quantité doit être d\'au moins 0,01 kg.';
    if (q > _disponible) return 'Au plus ${kg(_disponible)} disponibles.';
    return null;
  }

  Future<void> _envoyer(String moyen) async {
    if (!_form.currentState!.validate()) return;
    setState(() { _envoi = true; _erreur = null; });
    try {
      final res = await ApiService.passerCommande({
        'stock_id': widget.produit['stock_id'],
        'quantite': _quantite,
        ...widget.lieu,
        'moyen': moyen,
      }, cleIdempotence: _cle);
      if (mounted) Navigator.pop(context, res is Map ? res : <String, dynamic>{});
    } on ApiException catch (e) {
      if (mounted) setState(() { _erreur = e.message; _envoi = false; });
    }
  }

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
        padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: Form(
          key: _form,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('Commander — ${widget.produit['produit'] ?? ''}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            Text('Disponible : ${kg(_disponible)}'
                '${_prixUnitaire != null ? ' — ${fcfa(_prixUnitaire!)} / kg' : ''}',
                style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 16),
            // Lot 9 : − / + pour choisir sans clavier (pas de 10 kg), le champ reste modifiable
            Row(children: [
              IconButton.filledTonal(key: const Key('commande-moins'), iconSize: 30, icon: const Icon(Icons.remove),
                  onPressed: () => _ajuster(-_pas)),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(
                key: const Key('commande-quantite'),
                controller: _quantiteCtl,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Quantité (kg) *'),
                validator: _validerQuantite,
              )),
              const SizedBox(width: 8),
              IconButton.filledTonal(key: const Key('commande-plus'), iconSize: 30, icon: const Icon(Icons.add),
                  onPressed: () => _ajuster(_pas)),
            ]),
            if (_prixUnitaire != null && (_quantite ?? 0) > 0) ...[
              const SizedBox(height: 12),
              Text('Total : ${fcfa(((_quantite! * _prixUnitaire!) * 100).round() / 100)}',
                  key: const Key('commande-total'),
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              if (_devis != null)
                Text('À payer avec la livraison : ${fcfa(nombre(_devis!['total_a_payer']) ?? 0)} (livraison ${fcfa(nombre(_devis!['frais_livraison']) ?? 0)})',
                    key: const Key('commande-a-payer'), style: const TextStyle(color: AppTheme.vertFonce, fontWeight: FontWeight.w600)),
            ],
            if (_erreur != null) ...[
              const SizedBox(height: 12),
              Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
            ],
            const SizedBox(height: 16),
            // Lot 13 : un seul appui commande et paie
            BoutonsPaiement(onChoix: _envoi ? null : _envoyer, libelleWave: _envoi ? 'Envoi…' : 'Payer avec Wave'),
            if (widget.surPanier != null)
              OutlinedButton.icon(
                key: const Key('ajouter-panier'),
                icon: const Icon(Icons.add_shopping_cart),
                label: const Text('Ajouter au panier'),
                onPressed: _envoi ? null : () {
                  if (!_form.currentState!.validate()) return;
                  widget.surPanier!(_quantite!);
                  Navigator.pop(context);
                },
              ),
            TextButton(onPressed: _envoi ? null : () => Navigator.pop(context), child: const Text('Annuler')),
          ]),
        ),
      );
}
