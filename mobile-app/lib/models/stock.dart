import '../utils/nombres.dart';

class Stock {
  final int id;
  final String produit;
  /// Stock physique (en entrepôt).
  final double quantite;
  /// Promis à des commandes confirmées, pas encore parties.
  final double quantiteReservee;
  /// Ce que les acheteurs peuvent encore commander.
  final double quantiteDisponible;
  final double? seuilAlerte;
  final String? dateEntree;
  final String statut;
  final Map<String, dynamic>? entrepot;
  final Map<String, dynamic>? production;

  const Stock({
    required this.id,
    required this.produit,
    required this.quantite,
    this.quantiteReservee = 0,
    double? quantiteDisponible,
    this.seuilAlerte,
    this.dateEntree,
    required this.statut,
    this.entrepot,
    this.production,
  }) : quantiteDisponible = quantiteDisponible ?? quantite - quantiteReservee;

  /// L'alerte porte sur le disponible : une quantité réservée n'est plus vendable.
  bool get enAlerte =>
      seuilAlerte != null && quantiteDisponible <= seuilAlerte!;

  factory Stock.fromJson(Map<String, dynamic> j) => Stock(
        id: j['id'],
        produit: j['produit'],
        quantite: nombre(j['quantite']) ?? 0,
        quantiteReservee: nombre(j['quantite_reservee']) ?? 0,
        quantiteDisponible: nombre(j['quantite_disponible']),
        seuilAlerte: nombre(j['seuil_alerte']),
        dateEntree: j['date_entree'],
        statut: j['statut'] ?? 'disponible',
        entrepot: j['entrepot'],
        production: j['production'],
      );
}
