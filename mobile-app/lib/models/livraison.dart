class Livraison {
  final int id;
  final String numeroLivraison;
  final String? origine;
  final String? destination;
  final String? dateDepart;
  final String? dateLivraison;
  final String statut;
  final Map<String, dynamic>? commande;

  const Livraison({
    required this.id,
    required this.numeroLivraison,
    this.origine,
    this.destination,
    this.dateDepart,
    this.dateLivraison,
    required this.statut,
    this.commande,
  });

  factory Livraison.fromJson(Map<String, dynamic> j) => Livraison(
        id: j['id'],
        numeroLivraison: j['numero_livraison'] ?? '',
        origine: j['origine'],
        destination: j['destination'],
        dateDepart: j['date_depart'],
        dateLivraison: j['date_livraison'],
        statut: j['statut'],
        commande: j['commande'],
      );
}
