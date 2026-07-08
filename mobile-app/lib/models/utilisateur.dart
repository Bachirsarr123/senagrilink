class Utilisateur {
  final int id;
  final String nom;
  final String prenom;
  final String email;
  final String? telephone;
  final String role;
  final String statut;
  final Map<String, dynamic>? producteur;
  final Map<String, dynamic>? entrepot;
  final Map<String, dynamic>? acheteurGros;
  final Map<String, dynamic>? transporteur;

  const Utilisateur({
    required this.id,
    required this.nom,
    required this.prenom,
    required this.email,
    this.telephone,
    required this.role,
    required this.statut,
    this.producteur,
    this.entrepot,
    this.acheteurGros,
    this.transporteur,
  });

  factory Utilisateur.fromJson(Map<String, dynamic> j) => Utilisateur(
        id: j['id'],
        nom: j['nom'],
        prenom: j['prenom'],
        email: j['email'],
        telephone: j['telephone'],
        role: j['role'],
        statut: j['statut'],
        producteur: j['producteur'] as Map<String, dynamic>?,
        entrepot: j['entrepot'] as Map<String, dynamic>?,
        // Laravel sérialise la relation Eloquent acheteurGros() en snake_case.
        acheteurGros: j['acheteur_gros'] as Map<String, dynamic>?,
        transporteur: j['transporteur'] as Map<String, dynamic>?,
      );

  String get nomComplet => '$prenom $nom';
}
