# Lot 10 — Encore moins d'appuis : compte rendu

*Branche `claude/lot10-encore-moins-d-appuis`, partie de `claude/lot9-guidage-simplicite`. Rien n'a été fusionné, et aucun APK n'a été publié.*

## 1. Ce qui change (site et application)

| Rôle | Lot 9 | Lot 10 |
|---|---|---|
| **Transporteur, dépôt** | Accepter → Je suis chez le producteur → Chargée → Je pars → Arrivé : **5 appuis** | **J'accepte** → *arrivée chez le producteur détectée par le GPS (moins de 150 m), sans appui, annoncée à voix haute* → **Démarrer** (chargement et départ) → **Je suis arrivé** : **3 appuis** |
| **Transporteur, livraison** | Accepter → Chargée → Je pars → Arrivé : **4 appuis**, puis le code | **J'accepte** → **Démarrer** → **Je suis arrivé** : **3 appuis**, puis le code de réception (la preuve de remise est gardée) |
| **Gestionnaire, dépôt** | Accepter → Confier : **2 appuis** | **Accepter et confier à Alioune Fall** : **1 appui** |
| **Gestionnaire, commande** | Accepter → Préparation → Prête → Confier : **4 appuis** | **Accepter**, puis **Prête — confier à Alioune Fall** : **2 appuis** |
| **Producteur qui livre lui-même** | Je pars → Arrivé | **Démarrer** → **Je suis arrivé** |

**Ce qui ne change pas :**
- **Chaque étape reste enregistrée et datée** dans l'historique, avec son auteur : prise en charge, arrivée au point de collecte, chargement, départ, arrivée. Seuls les appuis sont regroupés : la traçabilité est intacte. Un test le vérifie étape par étape.
- Un appui reste **annulable pendant quelques secondes**.
- Les refus et les annulations gardent leur confirmation et leur motif.
- La réception à l'entrepôt (quantité, état, code du lot) et la remise à l'acheteur (code à 4 chiffres) restent des saisies, car elles servent de preuve.
- **Aucun transporteur libre :** le gestionnaire retrouve les étapes simples, puis choisit lui-même.

Le serveur choisit le gros bouton (`NavigationController::actionPrincipale`) et les regroupements (`AFaire::avecAction`). Le site et l'application suivent la même règle.

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests du serveur, SQLite | Local | **218 réussis**, dont 3 nouveaux : un dépôt en 1 + 3 appuis avec l'historique complet, une commande en 2 appuis, le repli sans transporteur libre |
| Tests du site, compilation | Local | **116 réussis**, compilation sans avertissement |
| Tests de l'application, dont l'arrivée automatique et « Accepter et confier » | Local (Flutter 3.47.7) | **349 réussis** ; analyse sans erreur |

**Non vérifié :** aucun essai sur un vrai téléphone, en particulier la détection d'arrivée par le GPS (sa précision dépend du téléphone et du lieu).

## 3. Base de données

Aucune migration.

## 4. Vérifications GitHub

*À compléter.*

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16, cycle des migrations, garde de démarrage (exécution 39769627213) | `cac154e` | **Réussi** |
| Site : tests et compilation de production (exécution 39770635394). La première exécution (39769627129) avait échoué : un test laissait une fausse position GPS au test suivant. Le test est corrigé, et le site ne plante plus si le navigateur ne fournit pas la position. | `7c694d7` | **Réussi** |
| Mobile : analyse, tests, APK de test vers la préproduction construit et contrôlé, sans publication (exécution 39770643770) | `7c694d7` | **Réussi** |

