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
