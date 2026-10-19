import 'geo.dart';

enum EtatEtape { termine, actuel, prevu, alerte }

/// Une étape réellement enregistrée dans le parcours d'un lot (aucune n'est inventée).
/// Même construction que le site web (shared/suivi/chronologie.ts).
class EtapeChronologie {
  final String type; // recolte, entree, sortie, commande, depart, livree, planifiee, probleme
  final String titre;
  final String? date;
  /// Lieu déclaré lors de l'opération (jamais une position GPS).
  final String? lieu;
  final String? detail;
  final String? statut;
  EtatEtape etat;
  final int? livraisonId;

  EtapeChronologie({
    required this.type, required this.titre, this.date, this.lieu, this.detail, this.statut,
    this.etat = EtatEtape.termine, this.livraisonId,
  });
}

String? _joindre(List<String?> parts) {
  final p = parts.where((x) => x != null && x.trim().isNotEmpty).toList();
  return p.isEmpty ? null : p.join(' — ');
}

String? _kg(Object? q) => q is num ? '${q % 1 == 0 ? q.toInt() : q} kg' : null;

String? _nom(Object? p) {
  if (p is! Map) return null;
  final n = '${p['prenom'] ?? ''} ${p['nom'] ?? ''}'.trim();
  return n.isEmpty ? null : n;
}

List<EtapeChronologie> construireChronologie(Map<String, dynamic>? t) {
  if (t == null) return [];
  final etapes = <EtapeChronologie>[];

  final prod = t['etape_production'] as Map?;
  if (prod != null) {
    final quantite = prod['quantite_reelle'] ?? prod['quantite_estimee'];
    final producteur = _nom(prod['producteur']);
    etapes.add(EtapeChronologie(
      type: 'recolte', titre: 'Récolte', date: prod['date_recolte'] as String?,
      lieu: (prod['producteur'] as Map?)?['region'] as String?,
      detail: _joindre([
        prod['type_culture'] as String?,
        quantite != null ? '${_kg(quantite)}${prod['quantite_reelle'] == null ? ' estimés' : ''}' : null,
        producteur != null ? 'par $producteur' : null,
      ]),
    ));
  }

  for (final s in (t['etapes_stockage'] as List? ?? []).cast<Map>()) {
    final entrepot = _joindre([(s['entrepot'] as Map?)?['nom'] as String?, (s['entrepot'] as Map?)?['localisation'] as String?]);
    etapes.add(EtapeChronologie(
      type: 'entree', titre: 'Entrée en entrepôt', date: s['date_entree'] as String?, lieu: entrepot,
      detail: _joindre([s['produit'] as String?, _kg(s['quantite'])]), statut: s['statut'] as String?,
    ));
    if (s['date_sortie'] != null) {
      etapes.add(EtapeChronologie(type: 'sortie', titre: 'Sortie d’entrepôt', date: s['date_sortie'] as String?, lieu: entrepot));
    }

    for (final c in (s['commandes'] as List? ?? []).cast<Map>()) {
      final acheteur = _nom(c['acheteur']);
      etapes.add(EtapeChronologie(
        type: 'commande', titre: 'Commande ${c['numero_commande'] ?? ''}'.trim(), date: c['date_commande'] as String?,
        detail: _joindre([c['produit'] as String?, _kg(c['quantite']), acheteur != null ? 'pour $acheteur' : null]),
        statut: c['statut'] as String?,
      ));

      final l = c['livraison'] as Map?;
      if (l == null) continue;
      final statut = l['statut'] as String?;
      final transporteur = _nom(l['transporteur']);
      final vers = _joindre([
        l['destination'] != null ? 'Vers ${l['destination']}' : null,
        transporteur != null ? 'Transporteur : $transporteur' : null,
      ]);
      if (statut == 'en_attente' && l['date_depart'] == null) {
        etapes.add(EtapeChronologie(
          type: 'planifiee', titre: 'Livraison planifiée', lieu: l['origine'] as String?, detail: vers,
          statut: statut, etat: EtatEtape.prevu,
        ));
        continue;
      }
      if (l['date_depart'] != null || statut == 'en_cours') {
        etapes.add(EtapeChronologie(
          type: 'depart', titre: statut == 'en_cours' ? 'En cours de livraison' : 'Départ en livraison',
          date: l['date_depart'] as String?, lieu: l['origine'] as String?, detail: vers, statut: statut,
          etat: statut == 'en_cours' ? EtatEtape.actuel : EtatEtape.termine,
          livraisonId: statut == 'en_cours' ? l['id'] as int? : null,
        ));
      }
      if (statut == 'livree') {
        etapes.add(EtapeChronologie(
          type: 'livree', titre: 'Livrée', date: l['date_livraison'] as String?, lieu: l['destination'] as String?,
          detail: l['numero_livraison'] as String?, statut: statut,
        ));
      }
      if (statut == 'probleme') {
        etapes.add(EtapeChronologie(
          type: 'probleme', titre: 'Problème signalé sur la livraison', detail: l['numero_livraison'] as String?,
          statut: statut, etat: EtatEtape.alerte,
        ));
      }
    }
  }

  // Tri stable : étapes datées par date, puis alertes non datées, puis étapes prévues.
  int rang(EtapeChronologie e) => e.etat == EtatEtape.prevu ? 2 : (lireDate(e.date) == null ? 1 : 0);
  final indexes = {for (var i = 0; i < etapes.length; i++) etapes[i]: i};
  etapes.sort((a, b) {
    final r = rang(a).compareTo(rang(b));
    if (r != 0) return r;
    final da = lireDate(a.date), db = lireDate(b.date);
    if (da != null && db != null && da != db) return da.compareTo(db);
    return indexes[a]!.compareTo(indexes[b]!);
  });

  if (!etapes.any((e) => e.etat == EtatEtape.actuel || e.etat == EtatEtape.alerte)) {
    final termines = etapes.where((e) => e.etat == EtatEtape.termine);
    if (termines.isNotEmpty) termines.last.etat = EtatEtape.actuel;
  }
  return etapes;
}
