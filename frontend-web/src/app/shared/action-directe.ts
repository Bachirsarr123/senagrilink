import { Observable, throwError } from 'rxjs';
import { ApiService } from '../core/services/api.service';
import { CleSoumission } from './idempotence/cle-soumission';

/**
 * Lot 9 : actions faisables d'un appui (« À faire », page Conduite). Mêmes
 * noms que le serveur (App\Support\AFaire::ACTIONS_DIRECTES) et que l'appli.
 */
export function libelleActionDirecte(action: string, sujetType = 'reservation', transporteur?: string | null): string {
  switch (action) {
    case 'confirmer': return 'Accepter';
    case 'assigner':
    case 'organiser_livraison': return transporteur ? `Confier à ${transporteur}` : 'Choisir un transporteur';
    case 'accepter_mission': return "J'accepte la mission";
    case 'arriver_collecte': return 'Je suis chez le producteur';
    case 'charger': return 'Marchandise chargée';
    case 'partir': return sujetType === 'livraison' ? "Je pars vers l'acheteur" : "Je pars vers l'entrepôt";
    case 'arriver': return 'Je suis arrivé';
    case 'preparer': return 'Commencer la préparation';
    case 'marquer_prete': return 'Commande prête';
    default: return action;
  }
}

const ETAPES_DEPOT: Record<string, string> = { arriver_collecte: 'au_point_collecte', charger: 'chargee', partir: 'en_route', arriver: 'arrivee_entrepot' };
const ETAPES_LIVRAISON: Record<string, string> = { charger: 'chargee', partir: 'en_cours', arriver: 'arrivee' };

/** Étapes de transport : elles se font depuis la page Conduite. */
export const ACTIONS_CONDUITE = ['arriver_collecte', 'charger', 'partir', 'arriver'];

export function executerActionDirecte(api: ApiService, sujetType: string, id: number, action: string, transporteurId?: number): Observable<any> {
  if (sujetType === 'reservation') {
    if (action === 'confirmer') return api.confirmerReservation(id);
    if (action === 'assigner' && transporteurId) return api.assignerTransporteur(id, transporteurId);
    if (action === 'accepter_mission') return api.accepterMission(id);
    if (ETAPES_DEPOT[action]) return api.etapeReservation(id, ETAPES_DEPOT[action]);
  }
  if (sujetType === 'commande') {
    if (action === 'confirmer') return api.confirmerCommande(id);
    if (action === 'preparer') return api.preparerCommande(id);
    if (action === 'marquer_prete') return api.marquerCommandePrete(id);
    if (action === 'organiser_livraison' && transporteurId) return api.createLivraison({ commande_id: id, transporteur_id: transporteurId }, CleSoumission.generer());
  }
  if (sujetType === 'livraison') {
    if (action === 'accepter_mission') return api.accepterMissionLivraison(id);
    if (ETAPES_LIVRAISON[action]) return api.etapeLivraison(id, ETAPES_LIVRAISON[action]);
  }
  return throwError(() => ({ error: { message: 'Action indisponible ici : ouvrez la mission.' } }));
}
