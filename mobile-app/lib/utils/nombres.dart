/// Conversion tolérante des nombres reçus de l'API.
///
/// Sous PostgreSQL, un backend non corrigé renvoie les colonnes float/decimal
/// en chaînes (« 12.5 ») : un `as num` levait alors une erreur de type et
/// bloquait l'écran (P-07). On accepte donc nombre, chaîne numérique ou null.
double? nombre(Object? valeur) => switch (valeur) {
      num n => n.toDouble(),
      String s => double.tryParse(s.trim()),
      _ => null,
    };

/// Affichage d'une quantité ou d'un montant sans arrondi trompeur :
/// 12 → « 12 », 12.5 → « 12,5 », 0.25 → « 0,25 » (au plus 2 décimales).
String nombreLisible(num valeur) {
  final texte = valeur.toStringAsFixed(2).replaceFirst(RegExp(r'\.?0+$'), '');
  return texte.replaceAll('.', ',');
}

String kg(num valeur) => '${nombreLisible(valeur)} kg';

String fcfa(num valeur) => '${nombreLisible(valeur)} FCFA';

/// Prix de vente au kilo saisi par le producteur : obligatoire, au moins 1 FCFA.
String? validerPrixAuKilo(String? saisie) {
  final prix = nombre(saisie?.trim().replaceAll(',', '.'));
  if (prix == null) return 'Indique ton prix au kilo.';
  if (prix < 1) return 'Le prix doit être d\'au moins 1 FCFA.';
  return null;
}
