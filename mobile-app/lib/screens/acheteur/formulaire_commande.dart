import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../widgets/app_theme.dart';

/// Passer une commande sur un produit du catalogue (P-09 : absent du mobile).
///
/// Mêmes champs et mêmes contrôles que le site web : quantité obligatoire
/// (≥ 0,01 kg, au plus le stock affiché), prix proposé facultatif. Le serveur
/// revérifie la quantité disponible. La clé d'idempotence est créée à
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
  final _prixCtl = TextEditingController();
  final _cle = ApiService.nouvelleCleIdempotence();
  bool _envoi = false;
  String? _erreur;

  double get _disponible => nombre(widget.produit['quantite']) ?? 0;

  @override
  void dispose() {
    _quantiteCtl.dispose();
    _prixCtl.dispose();
    super.dispose();
  }

  String? _validerQuantite(String? v) {
    final q = nombre(v?.replaceAll(',', '.'));
    if (q == null) return 'Indiquez la quantité souhaitée.';
    if (q < 0.01) return 'La quantité doit être d\'au moins 0,01 kg.';
    if (q > _disponible) return 'Au plus ${_disponible.toStringAsFixed(_disponible % 1 == 0 ? 0 : 2)} kg disponibles.';
    return null;
  }

  String? _validerPrix(String? v) {
    if (v == null || v.trim().isEmpty) return null;
    final p = nombre(v.replaceAll(',', '.'));
    if (p == null || p < 0) return 'Prix invalide.';
    return null;
  }

  Future<void> _envoyer() async {
    if (!_form.currentState!.validate()) return;
    setState(() { _envoi = true; _erreur = null; });
    final prix = _prixCtl.text.trim();
    try {
      final res = await ApiService.passerCommande({
        'stock_id': widget.produit['stock_id'],
        'quantite': nombre(_quantiteCtl.text.replaceAll(',', '.')),
        if (prix.isNotEmpty) 'prix': nombre(prix.replaceAll(',', '.')),
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
            Text('Stock disponible : ${widget.produit['quantite']} kg', style: const TextStyle(color: Colors.grey)),
            const SizedBox(height: 16),
            TextFormField(
              key: const Key('commande-quantite'),
              controller: _quantiteCtl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Quantité souhaitée (kg) *'),
              validator: _validerQuantite,
            ),
            const SizedBox(height: 12),
            TextFormField(
              key: const Key('commande-prix'),
              controller: _prixCtl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Prix proposé (FCFA, facultatif)'),
              validator: _validerPrix,
            ),
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
