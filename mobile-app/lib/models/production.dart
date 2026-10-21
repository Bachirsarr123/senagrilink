import '../utils/nombres.dart';

class Production {
  final int id;
  final String codeTracabilite;
  final String? typeCulture;
  final double? superficie;
  final String? dateRecolte;
  final double? quantiteEstimee;
  final double? quantiteReelle;
  /// Prix de vente au kilo (FCFA), fixé par le producteur.
  final double? prixUnitaire;
  final String statut;
  /// Le lot a déjà été réceptionné en entrepôt.
  final bool enEntrepot;

  const Production({
    required this.id,
    required this.codeTracabilite,
    this.typeCulture,
    this.superficie,
    this.dateRecolte,
    this.quantiteEstimee,
    this.quantiteReelle,
    this.prixUnitaire,
    required this.statut,
    this.enEntrepot = false,
  });

  /// Lot entré en entrepôt : seul son prix reste modifiable (règle du serveur).
  bool get estVerrouille => statut == 'disponible' || enEntrepot;

  factory Production.fromJson(Map<String, dynamic> j) => Production(
        id: j['id'],
        codeTracabilite: j['code_tracabilite'],
        typeCulture: j['type_culture'],
        superficie: nombre(j['superficie']),
        dateRecolte: j['date_recolte'],
        quantiteEstimee: nombre(j['quantite_estimee']),
        quantiteReelle: nombre(j['quantite_reelle']),
        prixUnitaire: nombre(j['prix_unitaire']),
        statut: j['statut'] ?? 'en_attente',
        enEntrepot: (j['stocks'] is List) && (j['stocks'] as List).isNotEmpty,
      );
}
