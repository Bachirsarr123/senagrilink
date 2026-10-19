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

/// Raison d'une fin de session imposée par l'API.
enum FinSession { expiree, compteBloque }

class ApiService {
  /// Client HTTP (remplaçable dans les tests par un MockClient).
  static http.Client client = http.Client();

  /// Appelé quand l'API refuse la session (401 sur une requête authentifiée,
  /// ou 403 « compte_bloque ») ; branché par AuthProvider.
  static void Function(FinSession raison)? onSessionInvalide;

  // Pointe vers le backend public par défaut, pour pouvoir tester depuis
  // n'importe quel réseau (build GitHub Actions incluse). Pour tester contre
  // un backend local, surcharger au build :
  // flutter run --dart-define=API_URL=http://<IP_LAN>:8000/api
  static const String _baseUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'https://senagrilink-backend.onrender.com/api',
  );

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

  static Future<dynamic> _parse(http.Response res, {required bool avecJeton}) async {
    final body = jsonDecode(utf8.decode(res.bodyBytes));
    if (res.statusCode >= 200 && res.statusCode < 300) return body;

    // Session refusée sur une requête authentifiée : on efface la session locale.
    // Un 401 sur /login (mauvais mot de passe) n'est pas concerné, et un 403
    // ordinaire (permission insuffisante) ne déconnecte pas l'utilisateur.
    final code = body is Map ? body['code'] : null;
    if (avecJeton && (res.statusCode == 401 || (res.statusCode == 403 && code == 'compte_bloque'))) {
      await removeToken();
      onSessionInvalide?.call(res.statusCode == 401 ? FinSession.expiree : FinSession.compteBloque);
    }

    final msg = body is Map && body['message'] != null ? body['message'] : 'Erreur serveur (${res.statusCode})';
    throw ApiException(msg, statusCode: res.statusCode);
  }

  static bool _avecJeton(Map<String, String> h) => h.containsKey('Authorization');

  static Future<dynamic> get(String path) async {
    final h = await _headers();
    final res = await client.get(Uri.parse('$_baseUrl$path'), headers: h);
    return _parse(res, avecJeton: _avecJeton(h));
  }

  static Future<dynamic> post(String path, Map<String, dynamic> data, {bool auth = true}) async {
    final h = await _headers(auth: auth);
    final res = await client.post(Uri.parse('$_baseUrl$path'), headers: h, body: jsonEncode(data));
    return _parse(res, avecJeton: _avecJeton(h));
  }

  static Future<dynamic> put(String path, Map<String, dynamic> data) async {
    final h = await _headers();
    final res = await client.put(Uri.parse('$_baseUrl$path'), headers: h, body: jsonEncode(data));
    return _parse(res, avecJeton: _avecJeton(h));
  }

  static Future<dynamic> delete(String path) async {
    final h = await _headers();
    final res = await client.delete(Uri.parse('$_baseUrl$path'), headers: h);
    return _parse(res, avecJeton: _avecJeton(h));
  }

  // ── Auth ─────────────────────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(String email, String password) async =>
      await post('/login', {'email': email, 'mot_de_passe': password}, auth: false);

  static Future<Map<String, dynamic>> register(Map<String, dynamic> data) async =>
      await post('/register', data, auth: false);

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
