import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/utilisateur.dart';
import 'api_service.dart';

class AuthProvider extends ChangeNotifier {
  Utilisateur? _utilisateur;
  bool _loading = false;
  String? _erreur;
  String? _messageSession;

  Utilisateur? get utilisateur => _utilisateur;
  bool get isLoggedIn => _utilisateur != null;
  bool get loading => _loading;
  String? get erreur => _erreur;
  String? get role => _utilisateur?.role;
  /// Motif affiché sur l'écran de connexion après une fin de session imposée.
  String? get messageSession => _messageSession;

  AuthProvider() {
    ApiService.onSessionInvalide = _sessionInvalidee;
  }

  /// Rappelé par ApiService (session déjà effacée du stockage) : on vide l'état
  /// une seule fois, ce qui affiche l'écran de connexion sans boucle.
  void Function()? apresFinSession;

  void _sessionInvalidee(FinSession raison) {
    if (_utilisateur == null) return;
    _utilisateur = null;
    _messageSession = raison == FinSession.compteBloque
        ? 'Votre compte a été bloqué. Contactez l\'administrateur.'
        : 'Votre session a expiré. Veuillez vous reconnecter.';
    notifyListeners();
    apresFinSession?.call();
  }

  // ── Initialisation depuis SharedPreferences ──────────────────────────────
  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('agri_user');
    if (raw != null) {
      try {
        _utilisateur = Utilisateur.fromJson(jsonDecode(raw));
        notifyListeners();
      } catch (_) {}
    }
  }

  // ── Connexion ────────────────────────────────────────────────────────────
  Future<bool> login(String email, String password) async {
    _loading = true; _erreur = null; _messageSession = null; notifyListeners();
    try {
      final res = await ApiService.login(email, password);
      await _persistSession(res);
      _loading = false; notifyListeners();
      return true;
    } on ApiException catch (e) {
      _erreur = e.message; _loading = false; notifyListeners();
      return false;
    } catch (_) {
      _erreur = 'Erreur de connexion. Vérifiez votre réseau.';
      _loading = false; notifyListeners();
      return false;
    }
  }

  // ── Inscription ──────────────────────────────────────────────────────────
  Future<bool> register(Map<String, dynamic> data) async {
    _loading = true; _erreur = null; _messageSession = null; notifyListeners();
    try {
      final res = await ApiService.register(data);
      await _persistSession(res);
      _loading = false; notifyListeners();
      return true;
    } on ApiException catch (e) {
      _erreur = e.message; _loading = false; notifyListeners();
      return false;
    } catch (_) {
      _erreur = 'Erreur lors de l\'inscription.';
      _loading = false; notifyListeners();
      return false;
    }
  }

  // ── Déconnexion ──────────────────────────────────────────────────────────
  Future<void> logout() async {
    // Déconnexion volontaire : l'état est vidé d'abord, pour qu'un éventuel 401
    // de /logout (jeton déjà expiré) n'affiche pas « session expirée ».
    _utilisateur = null;
    _messageSession = null;
    notifyListeners();
    await ApiService.logout();
  }

  void clearError() { _erreur = null; notifyListeners(); }

  // ── Persistance ──────────────────────────────────────────────────────────
  Future<void> _persistSession(Map<String, dynamic> res) async {
    final prefs = await SharedPreferences.getInstance();
    await ApiService.saveToken(res['access_token']);
    await prefs.setString('agri_user', jsonEncode(res['utilisateur']));
    _utilisateur = Utilisateur.fromJson(res['utilisateur']);
  }
}
