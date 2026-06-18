class Commande {
  final int id;
  final String numeroCommande;
  final String produit;
  final double quantite;
  final double? prix;
  final String? dateCommande;
  final String statut;
  final bool alerteLivraison;
  final Map<String, dynamic>? livraison;
  final Map<String, dynamic>? stock;

  const Commande({
    required this.id,
    required this.numeroCommande,
    required this.produit,
    required this.quantite,
    this.prix,
    this.dateCommande,
    required this.statut,
    this.alerteLivraison = false,
    this.livraison,
    this.stock,
  });

  factory Commande.fromJson(Map<String, dynamic> j) => Commande(
        id: j['id'],
        numeroCommande: j['numero_commande'] ?? '',
        produit: j['produit'],
        quantite: (j['quantite'] as num).toDouble(),
        prix: (j['prix'] as num?)?.toDouble(),
        dateCommande: j['date_commande'],
        statut: j['statut'],
        alerteLivraison: j['alerte_livraison'] == true,
        livraison: j['livraison'],
        stock: j['stock'],
      );
}
