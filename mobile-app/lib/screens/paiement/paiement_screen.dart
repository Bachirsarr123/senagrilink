import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/nombres.dart';
import '../../utils/paiement.dart';
import '../../widgets/app_theme.dart';

/// Payer des commandes (lot 12) : Wave, Orange Money, ou espèces à la livraison.
/// Renvoie le message à afficher, ou null si l'acheteur renonce.
Future<String?> payerCommandes(BuildContext context, List<int> commandes, double montant) async {
  final moyen = await showModalBottomSheet<String>(
    context: context,
    builder: (c) => SafeArea(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(padding: const EdgeInsets.all(16), child: Text('Payer ${fcfa(montant)}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold))),
      ListTile(key: const Key('payer-wave'), leading: const Icon(Icons.waves, color: Color(0xFF1DC8F2)), title: const Text('Wave'), onTap: () => Navigator.pop(c, 'wave')),
      ListTile(key: const Key('payer-orange_money'), leading: const Icon(Icons.phone_android, color: Color(0xFFFF7900)), title: const Text('Orange Money'), onTap: () => Navigator.pop(c, 'orange_money')),
      ListTile(key: const Key('payer-especes'), leading: const Icon(Icons.payments_outlined), title: const Text('En espèces à la livraison'),
          subtitle: const Text('Vous payez le transporteur quand il arrive'), onTap: () => Navigator.pop(c, 'especes')),
      const SizedBox(height: 8),
    ])),
  );
  if (moyen == null || !context.mounted) return null;
  final res = await ApiService.payer(commandes, moyen, cleIdempotence: ApiService.nouvelleCleIdempotence());
  if (moyen == 'especes' || !context.mounted) return (res as Map)['message'] as String?;
  return Navigator.push<String>(context, MaterialPageRoute(builder: (_) => PaiementScreen(reference: (res as Map)['paiement']['reference'] as String)));
}

/// Écran de paiement. En attendant Wave Business, il simule celui du service de
/// paiement ; avec le vrai service, l'acheteur sera envoyé dans son application Wave.
class PaiementScreen extends StatefulWidget {
  final String reference;
  const PaiementScreen({super.key, required this.reference});
  @override
  State<PaiementScreen> createState() => _PaiementScreenState();
}

class _PaiementScreenState extends State<PaiementScreen> {
  Map<String, dynamic>? _p;
  String? _message;
  bool _envoi = false;

  @override
  void initState() {
    super.initState();
    ApiService.getPaiement(widget.reference).then((r) { if (mounted) setState(() => _p = ((r as Map)['paiement'] as Map).cast<String, dynamic>()); })
        .catchError((Object e) { if (mounted) setState(() => _message = e is ApiException ? e.message : 'Paiement introuvable.'); });
  }

  Future<void> _confirmer(String resultat) async {
    setState(() => _envoi = true);
    try {
      final r = await ApiService.simulerPaiement(widget.reference, resultat) as Map;
      setState(() { _p = (r['paiement'] as Map).cast<String, dynamic>(); _message = r['message'] as String?; _envoi = false; });
    } on ApiException catch (e) {
      setState(() { _message = e.message; _envoi = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    final orange = p?['moyen'] == 'orange_money';
    final couleur = orange ? const Color(0xFFFF7900) : const Color(0xFF0B5EA8);
    return Scaffold(
      appBar: AppBar(title: Text(moyensPaiement[p?['moyen']] ?? 'Paiement'), backgroundColor: couleur, foregroundColor: Colors.white),
      body: p == null
          ? Center(child: _message != null ? Text(_message!) : const CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(20), children: [
              if (p['fournisseur'] == 'simulation')
                Container(padding: const EdgeInsets.all(8), decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(8)),
                    child: const Text('Simulation : aucun argent réel n\'est débité.', textAlign: TextAlign.center, style: TextStyle(color: Color(0xFF92400E)))),
              const SizedBox(height: 16),
              Text(fcfa(nombre(p['montant']) ?? 0), key: const Key('paiement-montant'), textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 34, fontWeight: FontWeight.w800)),
              Text('Payer SenAgriLink · ${(p['commandes'] as List?)?.length ?? 0} commande(s)', textAlign: TextAlign.center),
              const SizedBox(height: 20),
              if (p['statut'] == 'initie') ...[
                Text('Depuis le numéro ${p['telephone'] ?? ''}', textAlign: TextAlign.center, style: const TextStyle(color: Colors.grey)),
                const SizedBox(height: 12),
                SizedBox(height: 56, child: ElevatedButton(
                  key: const Key('confirmer-paiement'),
                  style: ElevatedButton.styleFrom(backgroundColor: couleur),
                  onPressed: _envoi ? null : () => _confirmer('reussi'),
                  child: Text(_envoi ? 'Paiement…' : 'Confirmer le paiement', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                )),
                TextButton(onPressed: _envoi ? null : () => _confirmer('echoue'), child: const Text('Annuler')),
              ] else ...[
                Icon(p['statut'] == 'reussi' ? Icons.check_circle : Icons.error, size: 64, color: p['statut'] == 'reussi' ? AppTheme.vert : AppTheme.rouge),
                Text(_message ?? (p['statut'] == 'reussi' ? 'Paiement réussi.' : 'Paiement non abouti.'), key: const Key('paiement-resultat'),
                    textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
                if (p['statut'] == 'reussi') const Text('L\'argent est mis de côté : il ne sera versé au producteur qu\'une fois la marchandise reçue.',
                    textAlign: TextAlign.center, style: TextStyle(color: Colors.grey)),
                const SizedBox(height: 16),
                ElevatedButton(onPressed: () => Navigator.pop(context, _message), child: const Text('Retour à mes commandes')),
              ],
            ]),
    );
  }
}
