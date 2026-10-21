import '../utils/nombres.dart';

class Commande {
  final int id;
  final String numeroCommande;
  final String produit;
  final double quantite;
  /// Montant total (quantité × prix au kilo figé à la commande).
  final double? prix;
  /// Prix au kilo fixé par le producteur, figé au moment de la commande.
  final double? prixUnitaire;
  final String? dateCommande;
  final String statut;
  final bool alerteLivraison;
  final Map<String, dynamic>? livraison;
  final Map<String, dynamic>? stock;
  final Map<String, dynamic>? acheteur;

  const Commande({
    required this.id,
    required this.numeroCommande,
    required this.produit,
    required this.quantite,
    this.prix,
    this.prixUnitaire,
    this.dateCommande,
    required this.statut,
    this.alerteLivraison = false,
    this.livraison,
    this.stock,
    this.acheteur,
  });

  factory Commande.fromJson(Map<String, dynamic> j) => Commande(
        id: j['id'],
        numeroCommande: j['numero_commande'] ?? '',
        produit: j['produit'],
        quantite: nombre(j['quantite']) ?? 0,
        prix: nombre(j['prix']),
        prixUnitaire: nombre(j['prix_unitaire']),
        dateCommande: j['date_commande'],
        statut: j['statut'],
        alerteLivraison: j['alerte_livraison'] == true,
        livraison: j['livraison'],
        stock: j['stock'],
        acheteur: j['acheteur'],
      );
}
