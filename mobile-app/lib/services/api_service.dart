import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiException implements Exception {
  final String message;
  final int? statusCode;
  ApiException(this.message, {this.statusCode});
  @override
  String toString() => message;
}

class ApiService {
  static const String _baseUrl = 'http://10.0.2.2:8000/api';
  // 10.0.2.2 = localhost de la machine hôte depuis l'émulateur Android.
  // Sur appareil physique, remplacer par l'IP LAN de la machine.

  // ── Token ────────────────────────────────────────────────────────────────
  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('agri_token');
  }

  static Future<void> saveToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('agri_token', token);
  }

  static Future<void> removeToken() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('agri_token');
    await prefs.remove('agri_user');
  }

  // ── Requêtes HTTP ────────────────────────────────────────────────────────
  static Future<Map<String, String>> _headers({bool auth = true}) async {
    final h = {'Content-Type': 'application/json', 'Accept': 'application/json'};
    if (auth) {
      final token = await getToken();
      if (token != null) h['Authorization'] = 'Bearer $token';
    }
    return h;
  }

  static dynamic _parse(http.Response res) {
    final body = jsonDecode(utf8.decode(res.bodyBytes));
    if (res.statusCode >= 200 && res.statusCode < 300) return body;
    final msg = body['message'] ?? 'Erreur serveur (${res.statusCode})';
    throw ApiException(msg, statusCode: res.statusCode);
  }

  static Future<dynamic> get(String path) async {
    final res = await http.get(Uri.parse('$_baseUrl$path'), headers: await _headers());
    return _parse(res);
  }

  static Future<dynamic> post(String path, Map<String, dynamic> data, {bool auth = true}) async {
    final res = await http.post(Uri.parse('$_baseUrl$path'),
        headers: await _headers(auth: auth), body: jsonEncode(data));
    return _parse(res);
  }

  static Future<dynamic> put(String path, Map<String, dynamic> data) async {
    final res = await http.put(Uri.parse('$_baseUrl$path'),
        headers: await _headers(), body: jsonEncode(data));
    return _parse(res);
  }

  static Future<dynamic> delete(String path) async {
    final res = await http.delete(Uri.parse('$_baseUrl$path'), headers: await _headers());
    return _parse(res);
  }

  // ── Auth ─────────────────────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(String email, String password) =>
      post('/login', {'email': email, 'mot_de_passe': password}, auth: false);

  static Future<Map<String, dynamic>> register(Map<String, dynamic> data) =>
      post('/register', data, auth: false);

  static Future<void> logout() async {
    try { await post('/logout', {}); } catch (_) {}
    await removeToken();
  }

  // ── Productions ──────────────────────────────────────────────────────────
  static Future<dynamic> getProductions() => get('/productions');
  static Future<dynamic> storeProduction(Map<String, dynamic> data) => post('/productions', data);
  static Future<dynamic> updateProduction(int id, Map<String, dynamic> data) => put('/productions/$id', data);
  static Future<dynamic> getCommandesDisponibles() => get('/commandes/disponibles');

  // ── Stocks ───────────────────────────────────────────────────────────────
  static Future<dynamic> getStocks() => get('/stocks');
  static Future<dynamic> getAlertes() => get('/stocks/alertes');

  // ── Commandes (Acheteur) ─────────────────────────────────────────────────
  static Future<dynamic> getCatalogue() => get('/catalogue');
  static Future<dynamic> passerCommande(Map<String, dynamic> data) => post('/commandes', data);
  static Future<dynamic> updateCommande(int id, Map<String, dynamic> data) => put('/commandes/$id', data);
  static Future<dynamic> annulerCommande(int id) => delete('/commandes/$id');
  static Future<dynamic> getHistoriqueCommandes() => get('/commandes/historique');

  // ── Livraisons (Transporteur) ────────────────────────────────────────────
  static Future<dynamic> getLivraisons() => get('/livraisons');
  static Future<dynamic> updateStatutLivraison(int id, String statut) =>
      put('/livraisons/$id/statut', {'statut': statut});
  static Future<dynamic> signalerProbleme(int id) =>
      post('/livraisons/$id/probleme', {});

  // ── Traçabilité ──────────────────────────────────────────────────────────
  static Future<dynamic> getTracabilite(String code) => get('/tracabilite/$code');

  // ── Suivi GPS (Transporteur) ──────────────────────────────────────────────
  static Future<dynamic> envoyerPosition(double latitude, double longitude) =>
      post('/transporteur/position', {'latitude': latitude, 'longitude': longitude});
  static Future<dynamic> getPositionLivraison(int livraisonId) =>
      get('/livraisons/$livraisonId/position');

  // ── Profil ───────────────────────────────────────────────────────────────
  static Future<dynamic> updateProfile(Map<String, dynamic> data) => put('/profile', data);

  // ── Notifications ────────────────────────────────────────────────────────
  static Future<dynamic> getNotifications() => get('/notifications');

  // ── Réservations d'entrepôt (Transporteur) ──────────────────────────────
  static Future<dynamic> getReservations() => get('/reservations');
  static Future<dynamic> marquerArriveeReservation(int id) =>
      put('/reservations/$id/arrivee', {});
}
