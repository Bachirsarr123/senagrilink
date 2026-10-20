import 'package:agri_platform_mobile/models/commande.dart';
import 'package:agri_platform_mobile/models/production.dart';
import 'package:agri_platform_mobile/models/stock.dart';
import 'package:agri_platform_mobile/utils/geo.dart';
import 'package:agri_platform_mobile/utils/nombres.dart';
import 'package:flutter_test/flutter_test.dart';

/// P-07 — sous PostgreSQL, un backend non corrigé renvoie les nombres en
/// chaînes (« 12.5 », « 14.7000000 ») : les modèles ne doivent plus planter.
void main() {
  test('nombre() accepte nombres, chaînes numériques et null', () {
    expect(nombre(12), 12.0);
    expect(nombre(12.5), 12.5);
    expect(nombre('12.5'), 12.5);
    expect(nombre(' 80 '), 80.0);
    expect(nombre(null), isNull);
    expect(nombre('abc'), isNull);
  });

  test('stock, commande et production au format PostgreSQL (chaînes)', () {
    final s = Stock.fromJson({'id': 1, 'produit': 'riz', 'quantite': '80.5', 'seuil_alerte': '100', 'statut': 'disponible'});
    expect(s.quantite, 80.5);
    expect(s.enAlerte, isTrue); // comparaison numérique, pas lexicale

    final c = Commande.fromJson({'id': 2, 'numero_commande': 'CMD-1', 'produit': 'riz', 'quantite': '10', 'prix': '1500.50', 'statut': 'en_attente'});
    expect(c.quantite, 10.0);
    expect(c.prix, 1500.5);

    final p = Production.fromJson({'id': 3, 'code_tracabilite': 'LOT-1', 'type_culture': 'mil', 'statut': 'en_attente',
      'superficie': '1.5', 'quantite_estimee': '100', 'quantite_reelle': null});
    expect(p.superficie, 1.5);
    expect(p.quantiteEstimee, 100.0);
    expect(p.quantiteReelle, isNull);
  });

  test('mêmes modèles au format SQLite / backend corrigé (nombres)', () {
    expect(Stock.fromJson({'id': 1, 'produit': 'riz', 'quantite': 80, 'statut': 'disponible'}).quantite, 80.0);
  });

  test('coordonnées decimal renvoyées en chaînes : point reconnu sur la carte', () {
    expect(coordonnees({'latitude': '14.7000000', 'longitude': '-17.4400000'}), (lat: 14.7, lng: -17.44));
    expect(coordonnees({'latitude': null, 'longitude': '1'}), isNull);
  });
}
