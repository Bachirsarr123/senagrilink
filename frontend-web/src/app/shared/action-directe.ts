import { Observable, of, switchMap, throwError } from 'rxjs';
import { ApiService } from '../core/services/api.service';
import { CleSoumission } from './idempotence/cle-soumission';

/**
 * Lot 9 : actions faisables d'un appui (« À faire », page Conduite). Mêmes
 * noms que le serveur (App\Support\AFaire::ACTIONS_DIRECTES) et que l'appli.
 */
export function libelleActionDirecte(action: string, sujetType = 'reservation', transporteur?: string | null): string {
  switch (action) {
    case 'confirmer': return 'Accepter';
    case 'accepter_et_confier': return transporteur ? `Accepter et confier à ${transporteur}` : 'Accepter';
    case 'prete_et_confier': return transporteur ? `Prête — confier à ${transporteur}` : 'Commande prête';
    case 'demarrer': return 'Démarrer';
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

/**
 * Lot 10 : les actions regroupées enchaînent les étapes (chacune reste datée dans
 * l'historique) ; `statut` et `actions` disent par où commencer.
 */
export function executerActionDirecte(api: ApiService, sujetType: string, id: number, action: string, transporteurId?: number,
  statut?: string, actions: string[] = []): Observable<any> {
  const suite = (a: string) => () => executerActionDirecte(api, sujetType, id, a, transporteurId);
  if (sujetType === 'reservation' && action === 'accepter_et_confier') return api.confirmerReservation(id).pipe(switchMap(suite('assigner')));
  if (sujetType === 'commande' && action === 'prete_et_confier') {
    const preparer = statut === 'confirmee' ? api.preparerCommande(id) : of(null);
    return preparer.pipe(switchMap(() => api.marquerCommandePrete(id)), switchMap(suite('organiser_livraison')));
  }
  if (action === 'demarrer') {
    const charger = actions.includes('charger') ? executerActionDirecte(api, sujetType, id, 'charger') : of(null);
    return charger.pipe(switchMap(suite('partir')));
  }
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
