import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';

/// Passer une commande sur un produit du catalogue (P-09 : absent du mobile).
///
/// Mêmes champs et mêmes contrôles que le site web : quantité obligatoire
/// (≥ 0,01 kg, au plus le disponible affiché). Le prix au kilo est celui fixé
/// par le producteur : l'acheteur ne le saisit pas, il voit le total. Le
/// serveur revérifie la quantité disponible et fige le prix. La clé d'idempotence est créée à
/// l'ouverture et réutilisée si l'acheteur réessaie après une erreur : une
/// commande déjà enregistrée n'est jamais créée deux fois.
///
/// Renvoie le numéro de commande créé, ou null si l'acheteur renonce.
Future<String?> ouvrirFormulaireCommande(BuildContext context, Map<String, dynamic> produit) =>
    showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FormulaireCommande(produit: produit),
    );

class FormulaireCommande extends StatefulWidget {
  final Map<String, dynamic> produit;
  const FormulaireCommande({super.key, required this.produit});
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

  @override
  void initState() {
    super.initState();
    _quantiteCtl.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _quantiteCtl.dispose();
    super.dispose();
  }

  String? _validerQuantite(String? v) {
    final q = nombre(v?.replaceAll(',', '.'));
    if (q == null) return 'Indiquez la quantité souhaitée.';
    if (q < 0.01) return 'La quantité doit être d\'au moins 0,01 kg.';
    if (q > _disponible) return 'Au plus ${kg(_disponible)} disponibles.';
    return null;
  }

  Future<void> _envoyer() async {
    if (!_form.currentState!.validate()) return;
    setState(() { _envoi = true; _erreur = null; });
    try {
      final res = await ApiService.passerCommande({
        'stock_id': widget.produit['stock_id'],
        'quantite': _quantite,
      }, cleIdempotence: _cle);
      if (mounted) Navigator.pop(context, (res['commande']?['numero_commande'] ?? '') as String);
    } on ApiException catch (e) {
      if (mounted) setState(() { _erreur = e.message; _envoi = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
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
            TextFormField(
              key: const Key('commande-quantite'),
              controller: _quantiteCtl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Quantité souhaitée (kg) *'),
              validator: _validerQuantite,
            ),
            if (_prixUnitaire != null && (_quantite ?? 0) > 0) ...[
              const SizedBox(height: 12),
              Text('Total : ${fcfa(((_quantite! * _prixUnitaire!) * 100).round() / 100)}',
                  key: const Key('commande-total'),
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ],
            if (_erreur != null) ...[
              const SizedBox(height: 12),
              Text(_erreur!, style: const TextStyle(color: AppTheme.rouge)),
            ],
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _envoi ? null : _envoyer,
              child: Text(_envoi ? 'Envoi…' : 'Confirmer la commande'),
            ),
            TextButton(onPressed: _envoi ? null : () => Navigator.pop(context), child: const Text('Annuler')),
          ]),
        ),
      );
}
