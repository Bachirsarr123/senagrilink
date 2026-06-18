import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/utilisateur.dart';
import 'api_service.dart';

class AuthProvider extends ChangeNotifier {
  Utilisateur? _utilisateur;
  bool _loading = false;
  String? _erreur;

  Utilisateur? get utilisateur => _utilisateur;
  bool get isLoggedIn => _utilisateur != null;
  bool get loading => _loading;
  String? get erreur => _erreur;
  String? get role => _utilisateur?.role;

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
    _loading = true; _erreur = null; notifyListeners();
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
    _loading = true; _erreur = null; notifyListeners();
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
    await ApiService.logout();
    _utilisateur = null;
    notifyListeners();
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
