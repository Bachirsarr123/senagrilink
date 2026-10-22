import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:provider/provider.dart';
import '../../services/api_service.dart';
import '../../services/auth_provider.dart';
import '../../utils/chronologie.dart';
import '../../utils/geo.dart';
import '../../widgets/app_theme.dart';
import '../../widgets/status_badge.dart';
import '../acheteur/suivi_livraison_screen.dart';

/// Traçabilité d'un lot : résumé (produit, origine, état, dernier lieu
/// enregistré) puis chronologie des étapes réellement enregistrées.
class TracabiliteScreen extends StatefulWidget {
  /// Code ouvert directement (depuis la carte de suivi).
  final String? codeInitial;
  const TracabiliteScreen({super.key, this.codeInitial});

  @override
  State<TracabiliteScreen> createState() => _TracabiliteScreenState();
}

class _TracabiliteScreenState extends State<TracabiliteScreen> {
  final _codeCtl = TextEditingController();
  bool _loading = false;
  String? _erreur;
  Map<String, dynamic>? _resultat;

  @override
  void initState() {
    super.initState();
    if (widget.codeInitial != null) {
      _codeCtl.text = widget.codeInitial!;
      _rechercher();
    }
  }

  @override
  void dispose() { _codeCtl.dispose(); super.dispose(); }

  Future<void> _rechercher() async {
    final code = _codeCtl.text.trim().toUpperCase();
    if (code.isEmpty) return;
    setState(() { _loading = true; _erreur = null; _resultat = null; });
    try {
      final res = await ApiService.getTracabilite(code);
      if (!mounted) return;
      setState(() { _resultat = res['tracabilite'] as Map<String, dynamic>; _loading = false; });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() { _erreur = e.message; _loading = false; });
    } catch (_) {
      if (!mounted) return;
      setState(() { _erreur = 'Serveur injoignable. Vérifiez votre connexion puis réessayez.'; _loading = false; });
    }
  }

  // Le suivi en direct n'est accessible qu'au producteur et à l'acheteur (cf. backend PositionGpsController).
  bool get _peutSuivreEnDirect {
    final role = context.read<AuthProvider>().role;
    return role == 'producteur' || role == 'acheteur_gros';
  }

  @override
  Widget build(BuildContext context) {
    final etapes = construireChronologie(_resultat);
    return Scaffold(
      appBar: AppBar(title: const Text('Traçabilité d\'un lot')),
      body: SafeArea(
        top: false,
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 760),
            child: ListView(padding: const EdgeInsets.all(16), children: [
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: _codeCtl,
                    textCapitalization: TextCapitalization.characters,
                    textInputAction: TextInputAction.search,
                    decoration: const InputDecoration(
                      labelText: 'Code de traçabilité',
                      hintText: 'ex : LOT-2026-00001',
                      prefixIcon: Icon(Icons.qr_code_scanner_outlined),
                    ),
                    onSubmitted: (_) => _rechercher(),
                  ),
                ),
                const SizedBox(width: 12),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14)),
                  onPressed: _loading ? null : _rechercher,
                  child: _loading
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Text('Chercher'),
                ),
              ]),
              if (_erreur != null) ...[
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(10), border: Border.all(color: AppTheme.rouge)),
                  child: Row(children: [
                    const Icon(Icons.error_outline, color: AppTheme.rouge, size: 18),
                    const SizedBox(width: 8),
                    Expanded(child: Text(_erreur!, style: const TextStyle(color: AppTheme.rouge))),
                  ]),
                ),
              ],
              if (_resultat != null) ...[
                const SizedBox(height: 16),
                _Resume(resultat: _resultat!, etapes: etapes, peutSuivre: _peutSuivreEnDirect, ouvrirCarte: _ouvrirCarte),
                const SizedBox(height: 16),
                Card(
                  margin: EdgeInsets.zero,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      const Text('Chronologie', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17, color: AppTheme.vertFonce)),
                      const SizedBox(height: 14),
                      for (var i = 0; i < etapes.length; i++)
                        _Etape(etape: etapes[i], derniere: i == etapes.length - 1,
                            suivantePrevue: i + 1 < etapes.length && etapes[i + 1].etat == EtatEtape.prevu,
                            peutSuivre: _peutSuivreEnDirect, ouvrirCarte: _ouvrirCarte),
                      if ((_resultat!['etapes_stockage'] as List?)?.isEmpty ?? true)
                        const Padding(
                          padding: EdgeInsets.only(bottom: 8),
                          child: Text('Ce lot n’a pas encore été réceptionné en entrepôt.', style: TextStyle(color: Colors.grey)),
                        ),
                    ]),
                  ),
                ),
              ],
            ]),
          ),
        ),
      ),
    );
  }

  void _ouvrirCarte(int livraisonId) => Navigator.push(context, MaterialPageRoute(
        builder: (_) => SuiviLivraisonScreen(livraisonId: livraisonId),
      ));
}

class _Resume extends StatelessWidget {
  final Map<String, dynamic> resultat;
  final List<EtapeChronologie> etapes;
  final bool peutSuivre;
  final void Function(int) ouvrirCarte;
  const _Resume({required this.resultat, required this.etapes, required this.peutSuivre, required this.ouvrirCarte});

  @override
  Widget build(BuildContext context) {
    final prod = resultat['etape_production'] as Map?;
    final producteur = prod?['producteur'] as Map?;
    final enregistrees = etapes.where((e) => e.etat != EtatEtape.prevu).toList();
    final actuelle = etapes.where((e) => e.etat == EtatEtape.actuel || e.etat == EtatEtape.alerte).firstOrNull;
    final dernierLieu = enregistrees.reversed.where((e) => e.lieu != null).firstOrNull?.lieu;
    final enCours = etapes.where((e) => e.livraisonId != null).firstOrNull?.livraisonId;
    final nomProducteur = producteur == null ? null : '${producteur['prenom'] ?? ''} ${producteur['nom'] ?? ''}'.trim();

    return Container(
      decoration: BoxDecoration(
        color: Colors.white, borderRadius: BorderRadius.circular(14),
        border: const Border(top: BorderSide(color: AppTheme.vert, width: 4)),
        boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 6, offset: Offset(0, 2))],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Lot ${resultat['code_tracabilite'] ?? ''}', style: const TextStyle(fontFamily: 'monospace', fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 4),
            Text(prod?['type_culture'] ?? 'Produit non renseigné',
                style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: AppTheme.vertFonce)),
            if (nomProducteur != null && nomProducteur.isNotEmpty)
              Text('Produit par $nomProducteur${producteur?['region'] != null ? ' · ${producteur!['region']}' : ''}'),
          ])),
          // Lot 5 (Q13) : QR code du lot, scanné par le gestionnaire à la réception
          if (resultat['code_tracabilite'] is String)
            Semantics(label: 'QR code du lot', child: QrImageView(key: const Key('qr-lot'), data: resultat['code_tracabilite'] as String, size: 88, padding: EdgeInsets.zero)),
        ]),
        if (actuelle != null) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(color: AppTheme.vertClair, borderRadius: BorderRadius.circular(20)),
            child: Text(actuelle.titre, style: const TextStyle(fontWeight: FontWeight.bold, color: AppTheme.vertFonce)),
          ),
        ],
        const SizedBox(height: 12),
        _Fait('Dernier lieu enregistré', dernierLieu ?? 'Aucun lieu enregistré'),
        const SizedBox(height: 6),
        const Text('Lieux déclarés lors de chaque opération, et non une position GPS.',
            style: TextStyle(fontSize: 12, color: Colors.grey)),
        if (enCours != null && peutSuivre) ...[
          const SizedBox(height: 12),
          SizedBox(width: double.infinity, child: ElevatedButton.icon(
            icon: const Icon(Icons.map_outlined),
            label: const Text('Suivre la livraison sur la carte'),
            onPressed: () => ouvrirCarte(enCours),
          )),
        ],
      ]),
    );
  }
}

class _Fait extends StatelessWidget {
  final String libelle, valeur;
  const _Fait(this.libelle, this.valeur);
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: const Color(0xFFF9FAFB), borderRadius: BorderRadius.circular(10)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(libelle.toUpperCase(), style: const TextStyle(fontSize: 11, color: Colors.grey, fontWeight: FontWeight.w600)),
          const SizedBox(height: 2),
          Text(valeur, style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}

class _Etape extends StatelessWidget {
  final EtapeChronologie etape;
  final bool derniere, suivantePrevue, peutSuivre;
  final void Function(int) ouvrirCarte;
  const _Etape({required this.etape, required this.derniere, required this.suivantePrevue, required this.peutSuivre, required this.ouvrirCarte});

  static const _icones = {
    'recolte': Icons.eco, 'acheminement': Icons.local_shipping_outlined, 'entree': Icons.warehouse, 'sortie': Icons.output, 'commande': Icons.shopping_cart,
    'depart': Icons.local_shipping, 'livree': Icons.check_circle, 'planifiee': Icons.event, 'probleme': Icons.warning_amber_rounded,
  };

  String _date() {
    final d = lireDate(etape.date);
    if (d == null) return etape.etat == EtatEtape.prevu ? 'Date de départ non encore fixée' : 'Date non enregistrée';
    String p(int n) => n.toString().padLeft(2, '0');
    final jour = '${p(d.day)}/${p(d.month)}/${d.year}';
    return RegExp(r'\d{2}:\d{2}').hasMatch(etape.date!) ? '$jour à ${p(d.hour)}:${p(d.minute)}' : jour;
  }

  @override
  Widget build(BuildContext context) {
    final (fond, couleur) = switch (etape.etat) {
      EtatEtape.actuel => (AppTheme.vert, Colors.white),
      EtatEtape.prevu => (Colors.white, Colors.grey),
      EtatEtape.alerte => (const Color(0xFFFEE2E2), AppTheme.rouge),
      EtatEtape.termine => (AppTheme.vertClair, AppTheme.vertFonce),
    };
    return Semantics(
      label: etape.etat == EtatEtape.actuel ? 'Étape actuelle' : null,
      child: IntrinsicHeight(
        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Column(children: [
            Container(
              width: 38, height: 38,
              decoration: BoxDecoration(color: fond, shape: BoxShape.circle,
                  border: Border.all(color: etape.etat == EtatEtape.prevu ? Colors.grey.shade400 : const Color(0xFFB7E4C7), width: 2)),
              child: Icon(_icones[etape.type], size: 19, color: couleur),
            ),
            if (!derniere)
              Expanded(child: Container(width: 3, margin: const EdgeInsets.symmetric(vertical: 2),
                  color: suivantePrevue ? Colors.grey.shade300 : const Color(0xFFB7E4C7))),
          ]),
          const SizedBox(width: 12),
          Expanded(child: Padding(
            padding: EdgeInsets.only(top: 6, bottom: derniere ? 8 : 18),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(etape.titre, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15,
                    color: etape.etat == EtatEtape.prevu ? Colors.grey : (etape.etat == EtatEtape.alerte ? AppTheme.rouge : AppTheme.vertFonce))),
                if (etape.etat == EtatEtape.actuel) const _Marque('Étape actuelle', AppTheme.vert, Colors.white),
                if (etape.etat == EtatEtape.prevu) _Marque('Prévue', Colors.grey.shade100, Colors.grey.shade700),
              ]),
              const SizedBox(height: 2),
              Text(_date(), style: const TextStyle(fontSize: 13, color: Colors.grey)),
              if (etape.lieu != null) ...[
                const SizedBox(height: 4),
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Icon(Icons.place_outlined, size: 15, color: Colors.grey),
                  const SizedBox(width: 4),
                  Expanded(child: Text(etape.lieu!, style: const TextStyle(fontSize: 13))),
                ]),
              ],
              if (etape.detail != null) ...[
                const SizedBox(height: 4),
                Text(etape.detail!, style: const TextStyle(fontSize: 13)),
              ],
              if (etape.statut != null && etape.type != 'depart' && etape.type != 'livree') ...[
                const SizedBox(height: 6),
                StatusBadge(etape.statut!),
              ],
              if (etape.livraisonId != null && peutSuivre) ...[
                const SizedBox(height: 6),
                OutlinedButton.icon(
                  icon: const Icon(Icons.map_outlined, size: 18),
                  label: const Text('Voir sur la carte'),
                  onPressed: () => ouvrirCarte(etape.livraisonId!),
                ),
              ],
            ]),
          )),
        ]),
      ),
    );
  }
}

class _Marque extends StatelessWidget {
  final String texte;
  final Color fond, couleur;
  const _Marque(this.texte, this.fond, this.couleur);
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: fond, borderRadius: BorderRadius.circular(10)),
        child: Text(texte, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: couleur)),
      );
}
