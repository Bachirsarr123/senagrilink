import 'package:flutter/material.dart';
import '../../models/commande.dart';
import '../../services/api_service.dart';
import '../../widgets/info_card.dart';

class CommandesDisponiblesScreen extends StatefulWidget {
  const CommandesDisponiblesScreen({super.key});
  @override
  State<CommandesDisponiblesScreen> createState() => _CommandesDisponiblesScreenState();
}

class _CommandesDisponiblesScreenState extends State<CommandesDisponiblesScreen> {
  List<Commande> _commandes = [];
  bool _loading = true;
  String? _erreur;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _loading = true; _erreur = null; });
    try {
      final res = await ApiService.getCommandesDisponibles();
      setState(() {
        _commandes = (res['commandes_disponibles'] as List).map((e) => Commande.fromJson(e)).toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() { _erreur = e.message; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Commandes disponibles')),
    body: _loading
        ? const Center(child: CircularProgressIndicator())
        : _erreur != null
            ? ErrorState(message: _erreur!, onRetry: _charger)
            : _commandes.isEmpty
                ? const EmptyState(message: 'Aucune commande en attente.', icon: Icons.shopping_cart_outlined)
                : RefreshIndicator(
                    onRefresh: _charger,
                    child: ListView.builder(
                      itemCount: _commandes.length,
                      itemBuilder: (_, i) {
                        final c = _commandes[i];
                        final acheteur = c.commande?['acheteur']?['utilisateur'];
                        return Card(
                          child: ListTile(
                            leading: const CircleAvatar(child: Icon(Icons.shopping_basket_outlined)),
                            title: Text(c.produit, style: const TextStyle(fontWeight: FontWeight.bold)),
                            subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text('${c.quantite.toStringAsFixed(0)} kg demandés'),
                              if (c.prix != null) Text('${c.prix!.toStringAsFixed(0)} FCFA'),
                              if (acheteur != null) Text('Acheteur : ${acheteur['prenom']} ${acheteur['nom']}'),
                            ]),
                          ),
                        );
                      },
                    ),
                  ),
  );
}
