import 'package:flutter/material.dart';

class StatusBadge extends StatelessWidget {
  final String statut;
  const StatusBadge(this.statut, {super.key});

  @override
  Widget build(BuildContext context) {
    final (bg, fg, label) = _config(statut);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(12)),
      child: Text(label, style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.w600)),
    );
  }

  static (Color, Color, String) _config(String s) => switch (s) {
    'en_attente'  => (const Color(0xFFFEF9C3), const Color(0xFF854D0E), 'En attente'),
    'confirmee'   => (const Color(0xFFD1FAE5), const Color(0xFF065F46), 'Confirmée'),
    'annulee'     => (const Color(0xFFFEE2E2), const Color(0xFF991B1B), 'Annulée'),
    'livree'      => (const Color(0xFFDBEAFE), const Color(0xFF1E40AF), 'Livrée'),
    'en_cours'    => (const Color(0xFFE0E7FF), const Color(0xFF3730A3), 'En cours'),
    'probleme'    => (const Color(0xFFFEE2E2), const Color(0xFFDC2626), '⚠ Problème'),
    'disponible'  => (const Color(0xFFD1FAE5), const Color(0xFF065F46), 'Disponible'),
    'epuise'      => (const Color(0xFFF3F4F6), const Color(0xFF6B7280), 'Épuisé'),
    'actif'       => (const Color(0xFFD1FAE5), const Color(0xFF065F46), 'Actif'),
    'bloque'      => (const Color(0xFFFEE2E2), const Color(0xFF991B1B), 'Bloqué'),
    _             => (const Color(0xFFF3F4F6), const Color(0xFF6B7280), s),
  };
}
