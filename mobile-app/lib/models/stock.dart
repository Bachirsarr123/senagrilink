class Stock {
  final int id;
  final String produit;
  final double quantite;
  final double? seuilAlerte;
  final String? dateEntree;
  final String statut;
  final Map<String, dynamic>? entrepot;
  final Map<String, dynamic>? production;

  const Stock({
    required this.id,
    required this.produit,
    required this.quantite,
    this.seuilAlerte,
    this.dateEntree,
    required this.statut,
    this.entrepot,
    this.production,
  });

  bool get enAlerte =>
      seuilAlerte != null && quantite <= seuilAlerte!;

  factory Stock.fromJson(Map<String, dynamic> j) => Stock(
        id: j['id'],
        produit: j['produit'],
        quantite: (j['quantite'] as num).toDouble(),
        seuilAlerte: (j['seuil_alerte'] as num?)?.toDouble(),
        dateEntree: j['date_entree'],
        statut: j['statut'] ?? 'disponible',
        entrepot: j['entrepot'],
        production: j['production'],
      );
}
