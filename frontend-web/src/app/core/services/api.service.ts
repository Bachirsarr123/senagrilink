import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient) {}

  // ── Productions ────────────────────────────────────────────────────────────
  getProductions(): Observable<any> { return this.http.get(`${this.base}/productions`); }
  storeProduction(data: any): Observable<any> { return this.http.post(`${this.base}/productions`, data); }
  updateProduction(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/productions/${id}`, data); }
  getCommandesDisponibles(): Observable<any> { return this.http.get(`${this.base}/commandes/disponibles`); }

  // ── Stocks ─────────────────────────────────────────────────────────────────
  getStocks(): Observable<any> { return this.http.get(`${this.base}/stocks`); }
  entreeStock(data: any): Observable<any> { return this.http.post(`${this.base}/stocks/entree`, data); }
  sortieStock(data: any): Observable<any> { return this.http.post(`${this.base}/stocks/sortie`, data); }
  getAlertes(): Observable<any> { return this.http.get(`${this.base}/stocks/alertes`); }
  getRapport(): Observable<any> { return this.http.get(`${this.base}/stocks/rapport`); }
  confirmerCommande(id: number): Observable<any> { return this.http.put(`${this.base}/commandes/${id}/confirmer`, {}); }
  createLivraison(data: any): Observable<any> { return this.http.post(`${this.base}/livraisons`, data); }

  // ── Commandes (Acheteur) ───────────────────────────────────────────────────
  getCatalogue(): Observable<any> { return this.http.get(`${this.base}/catalogue`); }
  passerCommande(data: any): Observable<any> { return this.http.post(`${this.base}/commandes`, data); }
  updateCommande(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/commandes/${id}`, data); }
  annulerCommande(id: number): Observable<any> { return this.http.delete(`${this.base}/commandes/${id}`); }
  getHistoriqueCommandes(): Observable<any> { return this.http.get(`${this.base}/commandes/historique`); }

  // ── Livraisons (Transporteur) ──────────────────────────────────────────────
  getLivraisons(): Observable<any> { return this.http.get(`${this.base}/livraisons`); }
  updateStatutLivraison(id: number, statut: string): Observable<any> { return this.http.put(`${this.base}/livraisons/${id}/statut`, { statut }); }
  signalerProbleme(id: number): Observable<any> { return this.http.post(`${this.base}/livraisons/${id}/probleme`, {}); }

  // ── Commandes (Gestionnaire) ──────────────────────────────────────────────
  getCommandesPourConfirmation(): Observable<any> { return this.http.get(`${this.base}/commandes?statut=en_attente`); }

  // ── Admin ──────────────────────────────────────────────────────────────────
  getDashboard(): Observable<any> { return this.http.get(`${this.base}/admin/dashboard`); }
  getUtilisateurs(params?: any): Observable<any> { return this.http.get(`${this.base}/admin/utilisateurs`, { params }); }
  createUtilisateur(data: any): Observable<any> { return this.http.post(`${this.base}/admin/utilisateurs`, data); }
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
  storeReservation(data: any): Observable<any> { return this.http.post(`${this.base}/reservations`, data); }
  confirmerReservation(id: number): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/confirmer`, {}); }
  assignerTransporteur(id: number, transporteur_id: number): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/assigner-transporteur`, { transporteur_id }); }
  validerMarchandise(id: number, data: any): Observable<any> { return this.http.put(`${this.base}/reservations/${id}/valider`, data); }
  getTransporteurs(): Observable<any> { return this.http.get(`${this.base}/transporteurs`); }

  // ── Profil ─────────────────────────────────────────────────────────────────
  updateProfile(data: any): Observable<any> { return this.http.put(`${this.base}/profile`, data); }
}
