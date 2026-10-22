# Lot 11 — Pilote automatique et « Ma journée » du gestionnaire : compte rendu

*Branche `claude/lot11-pilote-automatique`, partie de `claude/lot10-encore-moins-d-appuis`. Rien n'a été fusionné, et aucun APK n'a été publié.*

## 1. Ce qui change

### Le pilote automatique (actif par défaut, chaque règle se coupe)

| Tâche | Avant | Maintenant |
|---|---|---|
| **Accepter un dépôt** | Le gestionnaire valide | **Automatique** quand la place le permet (même contrôle de capacité qu'à la main). Le producteur lit tout de suite « Dépôt accepté par l'entrepôt. Un transporteur va venir chercher la marchandise. » |
| **Confirmer une commande** | Le gestionnaire valide | **Automatique** : le stock est déjà réservé au moment de la commande |
| **Trouver un transporteur** | Le gestionnaire choisit | **Comme chez Yango** : la mission est proposée au transporteur **libre le plus proche** (du producteur pour un dépôt, de l'entrepôt pour une livraison). S'il **refuse**, elle passe tout de suite au suivant. S'il **ne répond pas** dans le délai (10 minutes par défaut, réglable de 5 à 30), elle lui est retirée, avec un message, puis passe au suivant. |
| **Plus aucun transporteur libre** | — | Le gestionnaire est prévenu une seule fois, par la notification « Aucun transporteur libre pour … : choisissez-en un vous-même ». |
| **Préparer une commande** | Préparation, prête, puis confier | **Un appui : « Commande prête »**. Le transporteur est ensuite trouvé tout seul. |

**Reste au gestionnaire**, parce que cela demande un humain sur place :
- la **réception** d'un dépôt (pesée, état, code du lot) ;
- la **préparation** ;
- les **incidents** ;
- les **refus** ;
- tout ce que le pilote ne peut pas faire : capacité dépassée, aucun transporteur libre.

**Traçabilité :** le pilote agit **au nom du gestionnaire de l'entrepôt**, avec exactement les mêmes contrôles et les mêmes notifications qu'à la main. Chaque étape est marquée **« automatique »** dans l'historique. Un test vérifie ces marques.

**Réglages :** sur la page « Ma journée », du site comme de l'application, avec trois interrupteurs et le délai de réponse.

**Sans tâche planifiée :** Render gratuit n'en propose pas. La relance des missions sans réponse se fait donc au fil des requêtes des utilisateurs, au plus une fois par minute. La commande `php artisan pilote:relancer` existe aussi pour un planificateur.

### « Ma journée » (nouvel accueil du gestionnaire)

- **À traiter** : seulement ce qui demande son attention (réception, incident, stock sous le seuil, mission sans transporteur). Les boutons d'action d'un appui sont gardés.
- **Arrivent** : chaque dépôt avec l'**agriculteur**, l'étape, le transporteur et l'**heure d'arrivée estimée**. Un bouton « Réceptionner » apparaît à l'arrivée.
- **À préparer** : chaque commande avec un **gros bouton « Commande prête »**.
- **Partent** : chaque livraison avec son transporteur (« doit accepter » s'il n'a pas encore répondu). Une livraison sans transporteur est **signalée en rouge**.
- La page s'actualise toute seule toutes les 30 secondes.

### Agriculteurs

- Recherche par **nom, téléphone ou région**.
- Une **fiche** par agriculteur : téléphone (appel en un appui sur l'application), stock dans l'entrepôt par lot, dépôts et ventes.
- Un entrepôt ne voit que les agriculteurs qui ont déposé chez lui.

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests du serveur, SQLite | Local | **224 réussis**, 10 ignorés (réservés à PostgreSQL), dont **6 nouveaux** sur le pilote. Ils couvrent : l'acceptation et le transporteur automatiques, le refus suivi du suivant, l'absence de réponse suivie du suivant, la commande confirmée puis la livraison confiée, les réglages, le dépôt trop gros, « Ma journée » et la fiche agriculteur. |
| Tests du serveur, PostgreSQL 16 | Local | **234 réussis** |
| Cycle des migrations, de 000025 à 000018 | Local, même script que la CI | Réussi |
| Tests du site, compilation | Local | **118 réussis**, compilation sans avertissement |
| Tests de l'application, dont « Ma journée » sur 5 formats et 2 tailles de texte | Local | **361 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur**, 3 comptes | Chromium | **6 contrôles sur 6 réussis** (voir ci-dessous) |

**Ce que vérifie le parcours dans le navigateur :**
- le dépôt est accepté et confié sans le gestionnaire ;
- la commande est confirmée d'office ;
- le gestionnaire arrive sur « Ma journée » et voit l'arrivée avec le transporteur trouvé ;
- « Commande prête » se fait d'un appui, et un transporteur est prévenu automatiquement ;
- la recherche d'un agriculteur ouvre sa fiche.

Ce parcours a aussi permis de corriger trois défauts :
- le message affiché au producteur après un dépôt était toujours « envoyée », même quand le dépôt était déjà accepté ;
- une commande apparaissait deux fois sur « Ma journée » ;
- le délai de réponse affiché était faux.

**Non vérifié :** aucun essai sur un vrai téléphone ; la relance réelle sans réponse, sur plusieurs minutes, en préproduction.

## 3. Base de données

- **Nouvelle migration** `2025_01_01_000025_create_pilote_automatique` : quatre réglages par entrepôt.
- **Reprise** : les entrepôts existants passent **en pilote automatique**, comme convenu. Chaque gestionnaire peut le couper.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000025_create_pilote_automatique.php --force`. Ce retour est vérifié à chaque exécution de `backend-ci.yml`.

## 4. Vérifications GitHub

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16, cycle des migrations 000025 → 000018, garde de démarrage (exécution 39778265763) | `1daf0d3` | **Réussi** |
| Site : tests et compilation de production (exécution 39778265730) | `1daf0d3` | **Réussi** |
| Mobile : analyse, tests, APK de test construit et contrôlé, sans publication (exécution 39778265755) | `1daf0d3` | **Réussi** |
