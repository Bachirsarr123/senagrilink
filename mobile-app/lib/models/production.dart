class Production {
  final int id;
  final String codeTracabilite;
  final String? typeCulture;
  final double? superficie;
  final String? dateRecolte;
  final double? quantiteEstimee;
  final double? quantiteReelle;
  final String statut;

  const Production({
    required this.id,
    required this.codeTracabilite,
    this.typeCulture,
    this.superficie,
    this.dateRecolte,
    this.quantiteEstimee,
    this.quantiteReelle,
    required this.statut,
  });

  factory Production.fromJson(Map<String, dynamic> j) => Production(
        id: j['id'],
        codeTracabilite: j['code_tracabilite'],
        typeCulture: j['type_culture'],
        superficie: (j['superficie'] as num?)?.toDouble(),
        dateRecolte: j['date_recolte'],
        quantiteEstimee: (j['quantite_estimee'] as num?)?.toDouble(),
        quantiteReelle: (j['quantite_reelle'] as num?)?.toDouble(),
        statut: j['statut'] ?? 'en_attente',
      );
}
