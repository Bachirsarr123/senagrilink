# Lot 13 — Commander et payer d'un seul appui

*Branche `claude/lot13-commander-payer`, partie de `claude/lot12-paiements`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié. Les paiements restent simulés, en attendant Wave Business.*

## Le problème

L'acheteur touchait « Confirmer la commande », puis lisait « l'entrepôt va la confirmer ». Pendant ce temps, l'entrepôt (ou son pilote automatique) confirmait, préparait et confiait la livraison à un transporteur. La commande n'était pourtant **pas payée** : pour payer, l'acheteur devait aller lui-même dans « Mes commandes ».

## Ce qui change

| Où | Avant | Maintenant |
|---|---|---|
| Catalogue (site et application) | « Confirmer la commande » | **Payer avec Wave**, **Orange Money** ou **En espèces à la livraison**. Un seul appui commande et paie. |
| Panier | « Commander le panier », puis une fenêtre de confirmation | Les trois mêmes boutons, sans fenêtre de confirmation |
| Wave / Orange Money | Commande passée, paiement à faire plus tard | La page de paiement s'ouvre aussitôt. Une fois le paiement confirmé : « commande validée, l'entrepôt la prépare ». |
| Espèces | Idem | Commande validée tout de suite. Message : « Vous paierez X FCFA en espèces au transporteur, à la livraison. » |
| Entrepôt et pilote automatique | Traitaient la commande même non payée | **Rien ne bouge avant le paiement.** La commande n'apparaît ni dans « À faire » ni dans « Ma journée », et « Confirmer » est refusé. Seul le refus reste possible. Dès le paiement, le pilote confirme. |
| Paiement abandonné | — | La quantité reste **gardée 30 minutes** pour l'acheteur. Ensuite, la commande est annulée, la quantité est rendue au stock et l'acheteur est prévenu. « Payer » reste disponible en secours dans « Mes commandes » pendant ce délai. |

### Règles du serveur

- `POST /commandes` accepte `moyen` (`wave`, `orange_money` ou `especes`) et crée le paiement dans la même requête :
  - payée en ligne, la quantité est réservée dès la commande, une seule fois (la confirmation ne la réserve pas à nouveau) ;
  - en espèces, le pilote confirme aussitôt.
- `Commande::attendPaiement()` est la règle unique. Elle s'applique à l'étape attendue (« Paiement attendu de l'acheteur »), aux actions, à « À faire », à « Ma journée » et à la confirmation.
- **Expiration** (`Paiements::expirer`, délai `DELAI_MIN` de 30 min) : elle tourne chaque minute avec `pilote:relancer` et au fil des requêtes. Elle ne touche que les commandes du nouveau parcours (quantité gardée à la commande). Les commandes plus anciennes restent payables depuis « Mes commandes ». Un paiement confirmé après l'expiration est refusé.
- Si l'acheteur annule pendant le paiement, la quantité gardée est rendue au stock. Pendant le paiement, la quantité ne peut plus être modifiée.
- Ancienne application (sans `moyen`) : la commande est enregistrée « à payer ». L'entrepôt ne la traite qu'après le paiement fait dans « Mes commandes ».

## Vérifications

| Type | Résultat |
|---|---|
| Serveur, SQLite | 232 réussis, 10 ignorés (ceux propres à PostgreSQL) |
| Serveur, PostgreSQL 16 | 242 réussis. Nouveaux tests : paiement d'un seul geste, refus de confirmer avant paiement, réservation unique, expiration à 30 min, annulation pendant le paiement, pilote après paiement. |
| Site (Vitest) | 125 réussis, dont `lot13.spec.ts` (Wave, Orange Money, espèces, panier sans fenêtre) |
| Application (Flutter 3.47.7) | 376 réussis, dont `commander_payer_lot13_test.dart` ; `flutter analyze` sans nouvelle remarque |
| Navigateur (Chromium, vrais serveurs) | 8 contrôles sur 8 : boutons de paiement, page de paiement ouverte directement, entrepôt bloqué avant paiement, absente de « Ma journée », confirmée par le pilote après paiement, espèces validées tout de suite |
| Téléphone réel | **Pas encore fait** : à vérifier sur l'APK de préproduction |

## Préproduction

`render-preproduction.yaml` pointe vers `claude/lot13-commander-payer`. Il n'y a **aucune nouvelle migration** et aucune nouvelle variable. Dans Render, changez la branche des deux services de préproduction (*Settings*), puis lancez un *Manual Deploy*.
