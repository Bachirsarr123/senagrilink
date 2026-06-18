class Utilisateur {
  final int id;
  final String nom;
  final String prenom;
  final String email;
  final String? telephone;
  final String role;
  final String statut;

  const Utilisateur({
    required this.id,
    required this.nom,
    required this.prenom,
    required this.email,
    this.telephone,
    required this.role,
    required this.statut,
  });

  factory Utilisateur.fromJson(Map<String, dynamic> j) => Utilisateur(
        id: j['id'],
        nom: j['nom'],
        prenom: j['prenom'],
        email: j['email'],
        telephone: j['telephone'],
        role: j['role'],
        statut: j['statut'],
      );

  String get nomComplet => '$prenom $nom';
}
