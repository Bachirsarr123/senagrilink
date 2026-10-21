import 'dart:async';
import 'dart:convert';
import 'dart:io' show IOException;
import 'dart:math';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiException implements Exception {
  final String message;
  final int? statusCode;
  /// Corps JSON de la réponse d'erreur (ex. statut d'une livraison sans position).
  final Map<String, dynamic>? donnees;
  ApiException(this.message, {this.statusCode, this.donnees});
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
  // Le jeton de session est conservé dans le stockage chiffré du système
  // (Keychain iOS, Keystore Android) jusqu'à la déconnexion volontaire ou un
  // refus de l'API ; le profil affiché (agri_user, non secret) reste dans
  // SharedPreferences.
  static const _cleJeton = 'agri_token';
  static const _stockage = FlutterSecureStorage();

  static Future<String?> getToken() async {
    final jeton = await _stockage.read(key: _cleJeton);
    if (jeton != null) return jeton;

    // Migration des sessions ouvertes avec une version précédente, où le jeton
    // était stocké en clair dans SharedPreferences : l'utilisateur reste connecté.
    final prefs = await SharedPreferences.getInstance();
    final ancien = prefs.getString(_cleJeton);
    if (ancien != null) {
      await _stockage.write(key: _cleJeton, value: ancien);
      await prefs.remove(_cleJeton);
    }
    return ancien;
  }

  static Future<void> saveToken(String token) async {
    await _stockage.write(key: _cleJeton, value: token);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_cleJeton);
  }

  static Future<void> removeToken() async {
    await _stockage.delete(key: _cleJeton);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_cleJeton);
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

  /// [jeton] : jeton envoyé avec la requête (null pour les routes publiques).
  static Future<dynamic> _parse(http.Response res, {required String? jeton}) async {
    // Réponse non JSON (page HTML d'un proxy 502/503, corps vide…) : jamais
    // d'exception brute, qui laissait les écrans en chargement infini (P-20).
    Object? body;
    try {
      body = res.bodyBytes.isEmpty ? null : jsonDecode(utf8.decode(res.bodyBytes));
    } on FormatException {
      body = null;
    }
    if (res.statusCode >= 200 && res.statusCode < 300) {
      // Sans code HTTP : un 200 illisible vient presque toujours d'un intermédiaire
      // (portail Wi-Fi captif, proxy), à traiter comme une coupure réseau.
      if (body == null) throw ApiException('Réponse inattendue du serveur. Vérifiez votre connexion puis réessayez.');
      return body;
    }

    // Session refusée sur une requête authentifiée : on efface la session locale.
    // Un 401 sur /login (mauvais mot de passe) n'est pas concerné, et un 403
    // ordinaire (permission insuffisante) ne déconnecte pas l'utilisateur.
    // Le refus doit viser le jeton encore enregistré : la réponse tardive d'une
    // requête émise avec un ancien jeton n'efface pas une session ouverte depuis.
    // Erreur réseau (levée avant _parse), 5xx ou réponse inattendue : session conservée.
    final code = body is Map ? body['code'] : null;
    // Un 401 sans corps JSON vient d'un intermédiaire (proxy, pare-feu), pas de
    // l'API : il ne doit pas déconnecter l'utilisateur.
    if (jeton != null && body is Map &&
        (res.statusCode == 401 || (res.statusCode == 403 && code == 'compte_bloque')) &&
        jeton == await getToken()) {
      await removeToken();
      onSessionInvalide?.call(res.statusCode == 401 ? FinSession.expiree : FinSession.compteBloque);
    }

    final msg = body is Map && body['message'] != null ? body['message'] : 'Erreur serveur (${res.statusCode})';
    throw ApiException(msg, statusCode: res.statusCode, donnees: body is Map<String, dynamic> ? body : null);
  }

  static String? _jeton(Map<String, String> h) => h['Authorization']?.substring('Bearer '.length);

  /// Délai maximal d'une requête : le backend Render peut mettre près d'une
  /// minute à sortir de veille ; au-delà, l'écran affiche une erreur au lieu
  /// d'attendre indéfiniment.
  static Duration delaiMaximal = const Duration(seconds: 60);

  /// Exécute la requête ; délai dépassé et absence de réseau deviennent une
  /// ApiException (statusCode null), que tous les écrans savent afficher.
  static Future<http.Response> _envoyer(Future<http.Response> Function() requete) async {
    try {
      return await requete().timeout(delaiMaximal);
    } on TimeoutException {
      throw ApiException('Le serveur ne répond pas. Vérifiez votre connexion puis réessayez.');
    } on IOException {
      // SocketException, HandshakeException (TLS), HttpException… : connexion impossible.
      throw ApiException('Serveur injoignable. Vérifiez votre connexion puis réessayez.');
    } on http.ClientException {
      throw ApiException('Serveur injoignable. Vérifiez votre connexion puis réessayez.');
    }
  }

  /// Clé d'idempotence d'une soumission de formulaire : à générer une fois par
  /// soumission et à réutiliser si l'utilisateur réessaie, pour qu'une création
  /// déjà enregistrée ne soit jamais dupliquée (P-25).
  static String nouvelleCleIdempotence() {
    final aleatoire = Random.secure();
    return List.generate(32, (_) => aleatoire.nextInt(16).toRadixString(16)).join();
  }

  static Future<dynamic> get(String path) async {
    final h = await _headers();
    final res = await _envoyer(() => client.get(Uri.parse('$_baseUrl$path'), headers: h));
    return _parse(res, jeton: _jeton(h));
  }

  static Future<dynamic> post(String path, Map<String, dynamic> data, {bool auth = true, String? cleIdempotence}) async {
    final h = await _headers(auth: auth);
    if (cleIdempotence != null) h['Idempotency-Key'] = cleIdempotence;
    final res = await _envoyer(() => client.post(Uri.parse('$_baseUrl$path'), headers: h, body: jsonEncode(data)));
    return _parse(res, jeton: _jeton(h));
  }

  static Future<dynamic> put(String path, Map<String, dynamic> data) async {
    final h = await _headers();
    final res = await _envoyer(() => client.put(Uri.parse('$_baseUrl$path'), headers: h, body: jsonEncode(data)));
    return _parse(res, jeton: _jeton(h));
  }

  static Future<dynamic> delete(String path) async {
    final h = await _headers();
    final res = await _envoyer(() => client.delete(Uri.parse('$_baseUrl$path'), headers: h));
    return _parse(res, jeton: _jeton(h));
  }

  // ── Auth ─────────────────────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(String email, String password) async =>
      await post('/login', {'email': email, 'mot_de_passe': password}, auth: false);

  static Future<Map<String, dynamic>> register(Map<String, dynamic> data) async =>
      await post('/register', data, auth: false);

  /// Déconnexion volontaire : la session locale est effacée AVANT l'appel réseau
  /// (qui peut durer jusqu'au délai maximal) ; une application fermée pendant cet
  /// appel ne restaure donc pas la session au lancement suivant. Le jeton capturé
  /// est ensuite révoqué côté serveur si le réseau le permet.
  static Future<void> logout() async {
    final jeton = await getToken();
    await removeToken();
    if (jeton == null) return;
    try {
      await _envoyer(() => client.post(Uri.parse('$_baseUrl/logout'), headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer $jeton',
          }, body: '{}'));
    } catch (_) {}
  }

  // ── Productions ──────────────────────────────────────────────────────────
  static Future<dynamic> getProductions() => get('/productions');
  static Future<dynamic> storeProduction(Map<String, dynamic> data, {String? cleIdempotence}) =>
      post('/productions', data, cleIdempotence: cleIdempotence);
  static Future<dynamic> updateProduction(int id, Map<String, dynamic> data) => put('/productions/$id', data);
  static Future<dynamic> getCommandesDisponibles() => get('/commandes/disponibles');

  // ── Stocks ───────────────────────────────────────────────────────────────
  static Future<dynamic> getStocks() => get('/stocks');
  static Future<dynamic> getAlertes() => get('/stocks/alertes');

  // ── Commandes (Acheteur) ─────────────────────────────────────────────────
  static Future<dynamic> getCatalogue() => get('/catalogue');
  static Future<dynamic> passerCommande(Map<String, dynamic> data, {String? cleIdempotence}) =>
      post('/commandes', data, cleIdempotence: cleIdempotence);
  static Future<dynamic> updateCommande(int id, Map<String, dynamic> data) => put('/commandes/$id', data);
  static Future<dynamic> annulerCommande(int id) => delete('/commandes/$id');
  static Future<dynamic> getHistoriqueCommandes() => get('/commandes/historique');
  static Future<dynamic> confirmerReception(int id) => put('/commandes/$id/reception', {});
  static Future<dynamic> demanderAnnulation(int id, {String? motif}) =>
      post('/commandes/$id/demande-annulation', {if (motif != null && motif.isNotEmpty) 'motif': motif});
  static Future<dynamic> declarerAnomalie(int id, String type, String description) =>
      post('/commandes/$id/anomalie', {'type': type, 'description': description});

  // ── Livraisons (Transporteur) ────────────────────────────────────────────
  static Future<dynamic> getLivraisons() => get('/livraisons');
  static Future<dynamic> updateStatutLivraison(int id, String statut) =>
      put('/livraisons/$id/statut', {'statut': statut});
  static Future<dynamic> signalerProbleme(int id) =>
      post('/livraisons/$id/probleme', {});
  static Future<dynamic> accepterLivraison(int id) => put('/livraisons/$id/accepter-mission', {});
  static Future<dynamic> refuserLivraison(int id, String motif) => put('/livraisons/$id/refuser-mission', {'motif': motif});
  /// Étape de livraison : chargee, en_cours, arrivee.
  static Future<dynamic> etapeLivraison(int id, String etape) => put('/livraisons/$id/etape', {'etape': etape});
  static Future<dynamic> remettreLivraison(int id, String code) => put('/livraisons/$id/remise', {'code': code});

  // ── Incidents ─────────────────────────────────────────────────────────────
  static Future<dynamic> declarerIncident(Map<String, dynamic> data, {String? cleIdempotence}) =>
      post('/incidents', data, cleIdempotence: cleIdempotence);

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

  // ── Dépôts en entrepôt (réservations) ─────────────────────────────────────
  static Future<dynamic> getReservations() => get('/reservations');
  static Future<dynamic> getEntrepotsDisponibles() => get('/entrepots/disponibles');
  static Future<dynamic> demanderDepot(Map<String, dynamic> data, {String? cleIdempotence}) =>
      post('/reservations', data, cleIdempotence: cleIdempotence);
  static Future<dynamic> annulerDepot(int id, {String? motif}) =>
      put('/reservations/$id/annuler', {if (motif != null && motif.isNotEmpty) 'motif': motif});
  static Future<dynamic> accepterMission(int id) => put('/reservations/$id/accepter-mission', {});
  static Future<dynamic> refuserMission(int id, String motif) =>
      put('/reservations/$id/refuser-mission', {'motif': motif});
  /// Étape de transport : au_point_collecte, chargee, en_route, arrivee_entrepot
  /// (transporteur de la mission, ou producteur qui livre lui-même).
  static Future<dynamic> etapeDepot(int id, String etape) =>
      put('/reservations/$id/etape', {'etape': etape});
}
