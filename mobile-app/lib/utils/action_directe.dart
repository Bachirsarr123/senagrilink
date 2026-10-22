import '../services/api_service.dart';
import 'livraison.dart';

/// Lot 9 : actions faisables d'un appui (« À faire », écran Conduite). Mêmes
/// noms que le serveur (App\Support\AFaire::ACTIONS_DIRECTES).

/// Libellé du bouton, écrit pour être compris d'un coup d'œil.
String libelleActionDirecte(String action, {String sujetType = 'reservation', String? transporteur}) => switch (action) {
      'confirmer' => 'Accepter',
      'assigner' || 'organiser_livraison' => transporteur != null ? 'Confier à $transporteur' : 'Choisir un transporteur',
      'accepter_mission' => 'J\'accepte la mission',
      'arriver_collecte' => 'Je suis chez le producteur',
      'charger' => 'Marchandise chargée',
      'partir' => sujetType == 'livraison' ? 'Je pars vers l\'acheteur' : 'Je pars vers l\'entrepôt',
      'arriver' => 'Je suis arrivé',
      'preparer' => 'Commencer la préparation',
      'marquer_prete' => 'Commande prête',
      _ => action,
    };

const _etapesDepot = {'arriver_collecte': 'au_point_collecte', 'charger': 'chargee', 'partir': 'en_route', 'arriver': 'arrivee_entrepot'};

/// Exécute l'action ; renvoie le message du serveur. Lève ApiException en cas de refus.
Future<String> executerActionDirecte(String sujetType, int id, String action, {int? transporteurId}) async {
  final dynamic res = switch ((sujetType, action)) {
    ('reservation', 'confirmer') => await ApiService.confirmerDepot(id),
    ('reservation', 'assigner') => await ApiService.assignerTransporteurDepot(id, transporteurId!),
    ('reservation', 'accepter_mission') => await ApiService.accepterMission(id),
    ('reservation', _) when _etapesDepot.containsKey(action) => await ApiService.etapeDepot(id, _etapesDepot[action]!),
    ('commande', 'confirmer') => await ApiService.confirmerCommande(id),
    ('commande', 'preparer') => await ApiService.preparerCommande(id),
    ('commande', 'marquer_prete') => await ApiService.marquerCommandePrete(id),
    ('commande', 'organiser_livraison') => await ApiService.organiserLivraison(id, transporteurId!, cleIdempotence: ApiService.nouvelleCleIdempotence()),
    ('livraison', 'accepter_mission') => await ApiService.accepterLivraison(id),
    ('livraison', _) when etapeLivraison.containsKey(action) => await ApiService.etapeLivraison(id, etapeLivraison[action]!),
    _ => throw ApiException('Action indisponible ici : ouvrez la mission.'),
  };
  return (res is Map ? res['message'] as String? : null) ?? 'C\'est fait.';
}

/// Actions qui partagent la position (départ) ou l'arrêtent (arrivée).
bool demarrePartage(String action) => action == 'partir';
bool arretePartage(String action) => action == 'arriver';
