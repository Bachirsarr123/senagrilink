import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient) {}

  /** En-tête Idempotency-Key d'une création (voir shared/idempotence/cle-soumission.ts). */
  private idempotence(cle?: string): { headers?: HttpHeaders } {
    return cle ? { headers: new HttpHeaders({ 'Idempotency-Key': cle }) } : {};
  }

  // ── Productions ────────────────────────────────────────────────────────────
  getProductions(): Observable<any> { return this.http.get(`${this.base}/productions`); }
  storeProduction(data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/productions`, data, this.idempotence(cle)); }
  updateProduction(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/productions/${id}`, data); }
  annulerProduction(id: number, motif?: string): Observable<any> { return this.http.put(`${this.base}/productions/${id}/annuler`, { motif }); }
  getCommandesDisponibles(): Observable<any> { return this.http.get(`${this.base}/commandes/disponibles`); }

  // ── Stocks ─────────────────────────────────────────────────────────────────
  getStocks(): Observable<any> { return this.http.get(`${this.base}/stocks`); }
  ajusterStock(id: number, data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/stocks/${id}/ajustement`, data, this.idempotence(cle)); }
  modifierSeuilStock(id: number, seuil_alerte: number | null): Observable<any> { return this.http.put(`${this.base}/stocks/${id}/seuil`, { seuil_alerte }); }
  getMouvementsStock(id: number): Observable<any> { return this.http.get(`${this.base}/stocks/${id}/mouvements`); }
  getAlertes(): Observable<any> { return this.http.get(`${this.base}/stocks/alertes`); }
  getRapport(): Observable<any> { return this.http.get(`${this.base}/stocks/rapport`); }
  confirmerCommande(id: number): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/confirmer`, {}); }
  createLivraison(data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/livraisons`, data, this.idempotence(cle)); }
  changerTransporteurLivraison(id: number, transporteur_id: number, motif?: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/transporteur`, { transporteur_id, motif }); }
  refuserCommande(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/refuser`, { motif }); }
  annulerConfirmationCommande(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/annuler-confirmation`, { motif }); }

  // ── Commandes (Acheteur) ───────────────────────────────────────────────────
  getCatalogue(): Observable<any> { return this.http.get(`${this.base}/catalogue`); }
  passerCommande(data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/commandes`, data, this.idempotence(cle)); }
  updateCommande(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/commandes/${id}`, data); }
  annulerCommande(id: number): Observable<any> { return this.http.delete(`${this.base}/commandes/${id}`); }
  getHistoriqueCommandes(): Observable<any> { return this.http.get(`${this.base}/commandes/historique`); }
  confirmerReception(id: number): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/reception`, {}); }
  demanderAnnulationCommande(id: number, motif?: string): Observable<any> { return this.http.post(`${this.base}/commandes/${id}/demande-annulation`, { motif }); }
  declarerAnomalie(id: number, type: string, description: string): Observable<any> { return this.http.post(`${this.base}/commandes/${id}/anomalie`, { type, description }); }

  // ── Livraisons (Transporteur) ──────────────────────────────────────────────
  getLivraisons(): Observable<any> { return this.http.get(`${this.base}/livraisons`); }
  updateStatutLivraison(id: number, statut: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/statut`, { statut }); }
  signalerProbleme(id: number): Observable<any> { return this.http.post(`${this.base}/livraisons/${id}/probleme`, {}); }
  accepterMissionLivraison(id: number): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/accepter-mission`, {}); }
  refuserMissionLivraison(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/refuser-mission`, { motif }); }
  /** Étape de livraison : chargee, en_cours, arrivee. */
  etapeLivraison(id: number, etape: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/etape`, { etape }); }
  remettreLivraison(id: number, code: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/remise`, { code }); }

  // ── Incidents ──────────────────────────────────────────────────────────────
  getIncidents(statut?: string): Observable<any> { return this.http.get(`${this.base}/incidents`, { params: statut ? { statut } : {} }); }
  declarerIncident(data: { sujet_type: string; sujet_id: number; type: string; description: string }, cle?: string): Observable<any> { return this.http.post(`${this.base}/incidents`, data, this.idempotence(cle)); }
  resoudreIncident(id: number, data: { decision: string; motif?: string; transporteur_id?: number }): Observable<any> { return this.http.put(`${this.base}/incidents/${id}/resoudre`, data); }

  // ── Commandes (Gestionnaire) ──────────────────────────────────────────────
  getCommandesEntrepot(): Observable<any> { return this.http.get(`${this.base}/commandes`); }
  preparerCommande(id: number): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/preparer`, {}); }
  marquerCommandePrete(id: number): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/prete`, {}); }

  // ── Admin ──────────────────────────────────────────────────────────────────
  getDashboard(): Observable<any> { return this.http.get(`${this.base}/admin/dashboard`); }
  getUtilisateurs(params?: any): Observable<any> { return this.http.get(`${this.base}/admin/utilisateurs`, { params }); }
  createUtilisateur(data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/admin/utilisateurs`, data, this.idempotence(cle)); }
  bloquerUtilisateur(id: number): Observable<any> { return this.http.put(`${this.base}/admin/utilisateurs/${id}/bloquer`, {}); }
  debloquerUtilisateur(id: number): Observable<any> { return this.http.put(`${this.base}/admin/utilisateurs/${id}/debloquer`, {}); }

  // ── Traçabilité ────────────────────────────────────────────────────────────
  getTracabilite(code: string): Observable<any> { return this.http.get(`${this.base}/tracabilite/${code}`); }

  // ── Suivi GPS ──────────────────────────────────────────────────────────────
  getPositionLivraison(id: number): Observable<any> { return this.http.get(`${this.base}/livraisons/${id}/position`); }

  // ── Notifications ──────────────────────────────────────────────────────────
  getNotifications(): Observable<any> { return this.http.get(`${this.base}/notifications`); }
  marquerNotificationLue(id: string): Observable<any> { return this.http.put(`${this.base}/notifications/${id}/lue`, {}); }

  // ── Réservations d'entrepôt ────────────────────────────────────────────────
  getEntrepotsDisponibles(): Observable<any> { return this.http.get(`${this.base}/entrepots/disponibles`); }
  getReservations(): Observable<any> { return this.http.get(`${this.base}/reservations`); }
  storeReservation(data: any, cle?: string): Observable<any> { return this.http.post(`${this.base}/reservations`, data, this.idempotence(cle)); }
  confirmerReservation(id: number): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/confirmer`, {}); }
  assignerTransporteur(id: number, transporteur_id: number, motif?: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/assigner-transporteur`, { transporteur_id, motif }); }
  retirerTransporteur(id: number, motif?: string): Observable<any> { return this.http.delete(`${this.base}/reservations/${id}/transporteur`, { body: { motif } }); }
  refuserReservation(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/refuser`, { motif }); }
  remettreReservationEnAttente(id: number, motif?: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/remettre-en-attente`, { motif }); }
  annulerReservation(id: number, motif?: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/annuler`, { motif }); }
  accepterMission(id: number): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/accepter-mission`, {}); }
  refuserMission(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/refuser-mission`, { motif }); }
  /** Étape de transport : au_point_collecte, chargee, en_route, arrivee_entrepot (transporteur ou producteur qui livre). */
  etapeReservation(id: number, etape: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/etape`, { etape }); }
  validerMarchandise(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/valider`, data); }
  refuserReception(id: number, motif: string): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/refuser-reception`, { motif }); }
  getTransporteurs(): Observable<any> { return this.http.get(`${this.base}/transporteurs`); }

  // ── Profil ─────────────────────────────────────────────────────────────────
  updateProfile(data: any): Observable<any> { return this.http.put(`${this.base}/profile`, data); }
}
