import '../services/api_service.dart';
import 'livraison.dart';

/// Lot 9 : actions faisables d'un appui (« À faire », écran Conduite). Mêmes
/// noms que le serveur (App\Support\AFaire::ACTIONS_DIRECTES).

/// Libellé du bouton, écrit pour être compris d'un coup d'œil.
String libelleActionDirecte(String action, {String sujetType = 'reservation', String? transporteur}) => switch (action) {
      'confirmer' => 'Accepter',
      'accepter_et_confier' => transporteur != null ? 'Accepter et confier à $transporteur' : 'Accepter',
      'prete_et_confier' => transporteur != null ? 'Prête — confier à $transporteur' : 'Commande prête',
      'demarrer' => 'Démarrer',
      'prete' => 'Commande prête',
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
/// Lot 10 : les actions regroupées enchaînent les étapes (chacune reste datée dans
/// l'historique) ; [statut] et [actions] disent par où commencer.
Future<String> executerActionDirecte(String sujetType, int id, String action,
    {int? transporteurId, String? statut, List<String> actions = const []}) async {
  switch ((sujetType, action)) {
    case ('reservation', 'accepter_et_confier'):
      await ApiService.confirmerDepot(id);
      return executerActionDirecte(sujetType, id, 'assigner', transporteurId: transporteurId);
    case ('commande', 'prete_et_confier'):
      if (statut == 'confirmee') await ApiService.preparerCommande(id);
      await ApiService.marquerCommandePrete(id);
      return executerActionDirecte(sujetType, id, 'organiser_livraison', transporteurId: transporteurId);
    case ('commande', 'prete'):
      // Lot 11 : préparation comprise ; le pilote automatique trouve ensuite le transporteur
      if (statut == 'confirmee') await ApiService.preparerCommande(id);
      final res = await ApiService.marquerCommandePrete(id);
      return (res is Map ? res['message'] as String? : null) ?? 'Commande prête.';
    case (_, 'demarrer'):
      // Chargement (s'il reste à faire) puis départ, en un appui
      if (actions.contains('charger')) await executerActionDirecte(sujetType, id, 'charger');
      return executerActionDirecte(sujetType, id, 'partir');
  }
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
bool demarrePartage(String action) => action == 'partir' || action == 'demarrer';
bool arretePartage(String action) => action == 'arriver';
