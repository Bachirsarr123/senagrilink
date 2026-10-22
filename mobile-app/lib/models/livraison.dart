import '../utils/nombres.dart';

class Livraison {
  final int id;
  final String numeroLivraison;
  final String? origine;
  final String? destination;
  final String? dateDepart;
  final String? dateLivraison;
  final String statut;
  final Map<String, dynamic>? commande;
  /// Actions possibles pour le transporteur connecté (décidées par le serveur).
  final List<String> actions;
  final String? prochaineEtape;
  /// Incidents ouverts sur la livraison.
  final List<Map<String, dynamic>> incidents;
  final double? destinationLatitude;
  final double? destinationLongitude;

  const Livraison({
    required this.id,
    required this.numeroLivraison,
    this.origine,
    this.destination,
    this.dateDepart,
    this.dateLivraison,
    required this.statut,
    this.commande,
    this.actions = const [],
    this.prochaineEtape,
    this.incidents = const [],
    this.destinationLatitude,
    this.destinationLongitude,
  });

  bool peut(String action) => actions.contains(action);

  Map<String, dynamic>? get acheteur => (commande?['acheteur'] as Map?)?['utilisateur'] as Map<String, dynamic>?;
  Map<String, dynamic>? get entrepot => (commande?['stock'] as Map?)?['entrepot'] as Map<String, dynamic>?;

  factory Livraison.fromJson(Map<String, dynamic> j) => Livraison(
        id: j['id'],
        numeroLivraison: j['numero_livraison'] ?? '',
        origine: j['origine'],
        destination: j['destination'],
        dateDepart: j['date_depart'],
        dateLivraison: j['date_livraison'],
        statut: j['statut'],
        commande: j['commande'],
        actions: ((j['actions'] as List?) ?? const []).cast<String>(),
        prochaineEtape: (j['prochaine_etape'] as Map?)?['libelle'] as String?,
        incidents: ((j['incidents'] as List?) ?? const []).cast<Map<String, dynamic>>(),
        destinationLatitude: nombre(j['destination_latitude']),
        destinationLongitude: nombre(j['destination_longitude']),
      );
}
