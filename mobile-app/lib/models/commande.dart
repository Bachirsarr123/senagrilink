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
  /// Actions possibles pour l'utilisateur connecté (décidées par le serveur).
  final List<String> actions;
  final String? prochaineEtape;
  /// Code à donner au transporteur à la remise (acheteur seulement, pendant la livraison).
  final String? codeReception;
  final String? panier;
  final List<Map<String, dynamic>> incidents;
  /// Lot 12 : ce que l'acheteur paie (marchandise + livraison) et où en est le paiement.
  final double? totalAPayer;
  final double? fraisLivraison;
  final String statutPaiement;

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
    this.actions = const [],
    this.prochaineEtape,
    this.codeReception,
    this.panier,
    this.incidents = const [],
    this.totalAPayer,
    this.fraisLivraison,
    this.statutPaiement = 'a_payer',
  });

  /// Payable maintenant (en ligne ou en espèces à la livraison).
  bool get aPayer => statutPaiement == 'a_payer' && !const ['annulee', 'refusee', 'receptionnee'].contains(statut) && (totalAPayer ?? 0) > 0;

  bool peut(String action) => actions.contains(action);

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
        actions: ((j['actions'] as List?) ?? const []).cast<String>(),
        prochaineEtape: (j['prochaine_etape'] as Map?)?['libelle'] as String?,
        codeReception: j['code_reception'] as String?,
        panier: j['panier'] as String?,
        incidents: ((j['incidents'] as List?) ?? const []).cast<Map<String, dynamic>>(),
        totalAPayer: nombre(j['total_a_payer']),
        fraisLivraison: nombre(j['frais_livraison']),
        statutPaiement: j['statut_paiement'] as String? ?? 'a_payer',
      );
}
