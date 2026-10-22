# SenAgriLink — Audit général et recommandation d'architecture

*Audit réalisé le 20 octobre 2026 sur `main` (commit `20ab14b`, après fusion des PR #1 à #6). Aucune modification de code, aucune donnée de production touchée, rien n'a été déployé.*

## 0. Méthode et niveau de preuve

Chaque constat porte l'un des niveaux de preuve suivants :

| Repère | Signification |
|---|---|
| **[API]** | Reproduit en appelant l'API réelle (serveur Laravel local, base de démonstration, comptes distincts) |
| **[NAV]** | Reproduit dans Chromium (site Angular servi localement), avec plusieurs comptes |
| **[CODE]** | Vérifié à la lecture du code (fichier:ligne indiqué) |
| **[TEST]** | Couvert par un test automatisé existant |
| **[HYP]** | Hypothèse forte, non reproduite dans les conditions réelles |
| **[PROD]** | Observé sur la production Render par une requête en lecture seule, sans authentification ni écriture |

**Ce qui n'a pas été fait :**
- Aucun test sur un appareil physique, Android ou iPhone.
- Aucun APK installé.
- Aucun accès aux variables d'environnement ni aux journaux Render.
- Aucune connexion à un compte de production.

**Suites automatisées exécutées sur `main` :**

| Suite | Résultat |
|---|---|
| Laravel, SQLite | 127 tests réussis, 3 ignorés |
| Laravel, PostgreSQL 16 | 130 tests réussis lors de la session précédente |
| Angular | 68 tests réussis |
| Flutter | 181 tests réussis |

Ces résultats prouvent que les éléments testés fonctionnent isolément. Ils ne prouvent **pas** que les parcours métier sont corrects. Les défauts critiques ci-dessous passent tous ces tests.

**Documents de référence :**
- `cahier-des-charges-technique.md` : 5 rôles, schéma de données, endpoints, 5 règles métier, cas d'utilisation UML.
- Le mémoire lui-même n'est pas dans le dépôt.

---

## 1. État des lieux réel

### 1.1 Composants

| Composant | Technologie | Taille | État global |
|---|---|---|---|
| Backend | Laravel 12, Sanctum, PostgreSQL (prod) / SQLite (tests) | 11 contrôleurs API, 12 modèles, 17 migrations, 17 fichiers de tests | Base saine (autorisations, verrous sur les commandes) ; règles métier incomplètes ; un défaut d'infrastructure probablement bloquant en production |
| Site web | Angular 21 (standalone, signals), installable comme application web | ~20 écrans | Le plus complet des deux clients ; nombreuses erreurs masquées en « liste vide » |
| Mobile | Flutter 3.47, Provider | 15 écrans | Couverture partielle des rôles ; deux défauts qui le rendent probablement inutilisable en production |
| Déploiement | Render (Docker backend, site statique, PostgreSQL) | `render.yaml` | Pas de serveur Reverb déployé alors que l'application l'utilise |

### 1.2 Écarts avec le cahier des charges

| Sujet | Cahier des charges | Réalité | Preuve |
|---|---|---|---|
| **Réservation d'entrepôt** et acheminement producteur → entrepôt par un transporteur | **Absent.** Le cahier ne prévoit que les livraisons de commandes. | Circuit complet ajouté en juillet 2026 : `reservations`, assignation, arrivée, réception | `routes/api.php`, `ReservationController` [CODE] |
| Producteur : « Enregistrer une entrée de stock » (CU Stocks) | Prévu | Absent : le producteur passe par une réservation | `routes/api.php:24-34` [CODE] |
| Transporteur : « Accepter une mission » (CU Logistique UC2) | Prévu | Absent : la mission est imposée | aucune route [CODE] |
| Gestionnaire/admin : « Confirmer la livraison » (UC6) | Prévu | Absent : seul le transporteur marque « livrée » | `LivraisonController::updateStatut` [CODE] |
| Transporteur : « Consulter l'itinéraire » (UC5) | Prévu | Partiel : origine et destination en texte libre, aucune carte côté transporteur | [CODE] |
| Gestionnaire : « Localiser un lot » (UC5 Stocks) | Prévu | Couvert indirectement par la traçabilité (code du lot) | [CODE] |
| Producteur : « Planifier les ventes » | Prévu (classe `planifierVente()`) | Écran de consultation sans aucune donnée enregistrée | `planifier-ventes.component.ts` [CODE] |
| Commande au statut `livree` | Prévu (contrainte CHECK) | Jamais attribué | voir P-03 [API] |
| Répartition web/mobile (§3) | Transporteur : mobile seul ; admin : web seul | Transporteur ajouté au web (PR #6) ; admin bloqué sur mobile | [CODE] |
| Prix | `commandes.prix` | Saisi librement par l'acheteur, aucun prix de référence | voir P-04 [API] |

**Conclusion :** le cahier des charges et le code ont divergé. Le projet a ajouté un circuit (réservation, acheminement, réception) que le cahier ignore. À l'inverse, trois cas d'utilisation du cahier n'existent pas. La première décision à prendre concerne le périmètre (voir Q1 à Q3).

---

## 2. Cartographie des rôles et des fonctionnalités

Légende : ✅ fonctionne · ⚠️ présent mais défectueux ou incomplet · ❌ absent.
Colonnes : API, Web, Mobile.

### Producteur
*Objectif : déclarer ses récoltes, les faire entreposer et les vendre.*

| Fonction | API | Web | Mobile |
|---|---|---|---|
| Enregistrer une récolte | ✅ | ✅ | ⚠️ spinner infini si erreur réseau |
| Modifier une récolte | ⚠️ modifiable même après vente (P-13) | ✅ | ⚠️ champ vidé → 422 |
| Annuler une récolte | ✅ | ✅ | ❌ |
| Réserver un entrepôt | ⚠️ capacité sans verrou ; lot réservable plusieurs fois | ✅ | ❌ |
| Annuler sa réservation | ✅ | ✅ | ❌ |
| Voir où se trouvent ses lots (stock restant) | ⚠️ données chargées, non exposées | ❌ | ❌ |
| « Commandes disponibles » | ⚠️ voit les prix proposés aux autres producteurs | ⚠️ lecture seule, sens flou | ⚠️ |
| Planifier les ventes | ❌ rien n'est enregistré | ⚠️ simple tableau | ⚠️ |
| Suivre la livraison de ses lots | ✅ | ✅ | ✅ |

**Ne doit pas pouvoir :** confirmer sa propre réservation, toucher au stock, voir l'identité ou le prix des acheteurs des autres. Les deux premiers points sont respectés ; le prix ne l'est pas (P-18).

### Gestionnaire d'entrepôt
*Objectif : réceptionner, stocker, préparer les commandes, organiser le transport.*

| Fonction | API | Web | Mobile |
|---|---|---|---|
| Traiter les réservations (confirmer, refuser, remettre en attente) | ⚠️ confirmation sans verrou (P-10) | ✅ | ❌ |
| Assigner, changer ou retirer un transporteur | ✅ | ✅ | ❌ |
| Valider la marchandise arrivée | ⚠️ double validation possible (P-05) ; quantité sans plafond (P-11) | ✅ | ❌ |
| Refuser une marchandise non conforme | ❌ (P-14) | ❌ | ❌ |
| Entrée ou sortie manuelle de stock | ⚠️ sortie sans verrou ; double débit possible (P-08, P-09) | ⚠️ saisie d'un identifiant brut | ❌ |
| Commandes : confirmer, refuser, annuler | ✅ | ⚠️ blocage si la livraison échoue après la confirmation (P-16) | ❌ |
| Créer une livraison | ⚠️ unicité non garantie ; transporteur facultatif | ✅ | ❌ |
| Suivre les livraisons de son entrepôt | ❌ aucune route | ❌ | ❌ |
| Alertes de seuil | ⚠️ règles divergentes | ⚠️ « tout va bien » affiché en cas d'erreur | ✅ lecture |
| Rapport | ✅ | ⚠️ cassé pour l'admin | ❌ |

### Acheteur en gros
*Objectif : trouver un produit, commander, être livré.*

| Fonction | API | Web | Mobile |
|---|---|---|---|
| Catalogue | ⚠️ aucun prix | ⚠️ pas de recherche ; erreurs masquées | ⚠️ lecture seule |
| Passer une commande | ⚠️ prix libre ; doublon au double clic (P-04, P-20) | ✅ | ❌ **aucun bouton Commander** |
| Modifier ou annuler une commande en attente | ✅ | ✅ | ✅ |
| Suivre sa commande jusqu'à la livraison | ⚠️ la commande ne devient jamais « livrée » (P-03) | ⚠️ | ⚠️ |
| Être alerté d'un problème de livraison | ⚠️ indicateur seulement, sans notification | ⚠️ | ⚠️ |
| Voir le motif d'un refus | ❌ un refus apparaît comme « Annulée » | ❌ | ❌ |

### Transporteur
*Objectif : connaître ses missions, les effectuer, signaler les incidents.*

| Fonction | API | Web | Mobile |
|---|---|---|---|
| Voir ses missions (acheminements et livraisons) | ✅ filtrage serveur | ✅ depuis la PR #6 | ✅ acheminements via une icône peu visible |
| Accepter ou refuser une mission | ❌ | ❌ | ❌ |
| Démarrer, livrer | ✅ | ✅ avec confirmation | ⚠️ sans confirmation ; liste non rafraîchie |
| Signaler un problème | ⚠️ sans description ni notification ; impasse (P-02) | ⚠️ | ⚠️ |
| Partager sa position GPS | ⚠️ positions non liées à la mission | ❌ | ⚠️ seulement écran ouvert, premier plan |

### Administrateur
*Objectif : gérer les comptes, superviser.*

| Fonction | API | Web | Mobile |
|---|---|---|---|
| Lister, créer, bloquer, débloquer | ✅ jetons révoqués, dernier admin protégé | ⚠️ création sans profil métier (P-15) ; erreurs de blocage silencieuses | ❌ écran bloquant **sans déconnexion** |
| Tableau de bord | ✅ | ✅ | — |
| Superviser commandes, livraisons, réservations, historique | ❌ aucune route de lecture de l'historique | ❌ | — |
| Agir à la place des autres rôles | ⚠️ possible sans trace (contournement de rôle) | par URL seulement | — |

**Fait vérifié [API][CODE] :** aucune action interdite à un rôle n'a pu être exécutée en contournant l'interface. Le contrôle d'accès serveur (rôle, propriétaire, périmètre de l'entrepôt) est le point le plus solide du projet. Il est testé : `PerimetreGestionnaireTest`, `RoleMiddlewareTest`, `MissionTransportTest`. L'exception est le passe-droit de l'administrateur, voulu mais non historisé.

---

## 3. Parcours métier de bout en bout

### Parcours 1 — Récolte → entrepôt (circuit hors cahier des charges)

```
Production : en_attente ──(réception validée)──▶ disponible        annulee (si non utilisée)
Réservation : en_attente ─▶ confirmee ─▶ [transporteur assigné] ─▶ arrivee_entrepot ─▶ enregistree
                 │              │  ↺ réassigner / retirer            │
                 ▼              ▼                                     └─ ❌ aucune sortie si non conforme
              refusee       en_attente (retour) / annulee (producteur, sans transporteur)
```

| Étape | Vérifié | Défauts |
|---|---|---|
| 1. Récolte enregistrée | [NAV] | — |
| 2. Demande de réservation | [NAV] | capacité vérifiée sans verrou (P-10) ; un lot déjà en stock peut être réservé à nouveau (P-12) ; **gestionnaire non notifié** |
| 3. Acceptation par l'entrepôt | [NAV] | confirmation sans verrou : double notification possible |
| 4. Assignation du transporteur | [NAV][TEST] | ✅ notification, réassignation, historique |
| 5. Le transporteur voit la mission | [NAV] | ✅ web ; mobile via une icône |
| 6. Arrivée signalée | [NAV] | pas d'état « en route » : la réassignation reste possible pendant le trajet |
| 7. Réception, stock créé | [CODE] | **double validation, donc double stock (P-05)** ; quantité reçue sans plafond ni écart tracé (P-11) ; `quantite_reelle` du lot non mise à jour |
| Marchandise refusée à l'arrivée | [CODE] | **impasse** : la réservation occupe la capacité pour toujours (P-14) |

### Parcours 2 — Commande → livraison (cœur du cahier des charges)

```
Commande : en_attente ─▶ confirmee (stock déduit, verrouillé ✅) ─────────────▶ ❌ jamais « livree »
              │  annulee (acheteur) / annulee (refus entrepôt)   annulee (entrepôt, sans livraison, stock recrédité ✅)
Livraison :              en_attente ─▶ en_cours ─▶ livree
                               └──────────┴──▶ probleme ──▶ ❌ aucune sortie
```

| Étape | Vérifié | Défauts |
|---|---|---|
| Catalogue | [API] | aucun prix |
| Commande | [API] | **prix libre (1 FCFA accepté)** ; double clic = 2 commandes ; quantité > stock bien refusée (422) ✅ |
| Confirmation | [API][TEST] | verrou et déduction atomique ✅ ; 2ᵉ confirmation au-delà du stock refusée ✅ |
| Livraison créée | [API] | unicité par commande non garantie en base ; transporteur facultatif ; adresses saisies à la main ; « date prévue » perdue |
| Livraison démarrée, livrée | [API] | **commande restée « confirmee » après la livraison (P-03)** |
| Problème de livraison | [API] | **impasse** : ni reprise, ni annulation, ni changement de transporteur ; personne n'est notifié ; aucune description (P-02) |

### Parcours 3 — Comptes et session
- Inscription libre en producteur, acheteur, transporteur **et gestionnaire d'entrepôt**, actifs immédiatement [CODE]. L'administrateur est bien refusé [API].
- Session persistante jusqu'à la déconnexion, révocable (PR #5) [TEST][NAV].
- **Changement de mot de passe affiché comme réussi mais ignoré** [CODE].
- **Adresse effacée à chaque enregistrement du profil web** [CODE].

### Parcours 4 — Traçabilité et suivi
- Traçabilité d'un lot : fonctionnelle, masque l'acheteur et le prix [TEST]. Visible par tout utilisateur connecté qui connaît le code (P-21).
- Suivi GPS acheteur et producteur : écrans soignés [TEST]. La source de positions (mobile transporteur) ne fonctionne qu'écran ouvert. Les positions ne sont pas liées à une mission (P-22).

---

## 4. Tableau des problèmes (avec preuves)

Chemins : `B/` = `backend/`, `W/` = `frontend-web/src/app/`, `M/` = `mobile-app/`.

### Critique

| # | Problème | Preuve | Niveau | Conséquence |
|---|---|---|---|---|
| P-01 | **Les actions qui envoient une notification répondent 500 alors qu'elles ont été exécutées.** Notifications diffusées en synchrone vers Reverb, qui n'est pas déployé. | `B/.env.example:26,41` (`BROADCAST_CONNECTION=reverb`, hôte = le backend lui-même) ; `render.yaml` ne définit pas cette variable ; `start.sh` ne lance pas Reverb ; `B/app/Notifications/*` sans file d'attente. Reproduit en local : `POST /commandes` répond **500**, commande créée ; `PUT …/confirmer` répond **500**, stock débité de 4 à 3. En production, `/apps/agriplatform/events` répond **404**. | [API] + [PROD] partiel | Si la variable n'est pas surchargée sur Render, chaque commande, confirmation, réservation, assignation ou livraison affiche une erreur à l'utilisateur alors qu'elle a eu lieu. Il recommence, ce qui donne des doublons, des refus « déjà confirmée » et des incohérences perçues. **Explication probable de nombreux symptômes constatés.** À confirmer dans les journaux Render. |
| P-02 | **Livraison « probleme » sans issue.** | `B/…/LivraisonController.php` (transitions sans `probleme`) ; reproduit : `probleme → en_cours` et `probleme → livree` répondent 422 | [API] | Commande, stock et transporteur bloqués définitivement ; personne n'est prévenu. |
| P-03 | **La commande ne passe jamais à « livrée ».** | Aucun code n'attribue `livree` ; reproduit : livraison `livree`, commande `confirmee` | [API] | Suivi acheteur faux, statistiques fausses, historique incohérent. |
| P-04 | **Prix fixé par l'acheteur.** | `StoreCommandeRequest` : `prix nullable` ; reproduit : prix 1 enregistré ; aucune colonne de prix sur le stock | [API] | Montants sans valeur ; une base de paiement future serait impossible. |
| P-05 | **Double réception d'une marchandise, donc double stock.** | `ReservationController::validerMarchandise` : statut vérifié hors verrou, transaction sans `lockForUpdate` | [CODE] | Un double clic ou une requête répétée (voir P-01) duplique la quantité vendable. |
| P-06 | **Android : l'APK de production ne déclare pas la permission INTERNET.** | `M/android/app/src/main/AndroidManifest.xml` (seulement la localisation) ; permission présente seulement en debug et profile ; la CI publie `--release` | [CODE] | L'application installée ne joint pas l'API. À confirmer en installant l'APK. |
| P-07 | **Les nombres arrivent en chaînes sous PostgreSQL, ce qui fait planter le mobile.** | Mesuré sur PostgreSQL 16 : `{"quantite":"12.5","capacite":"100","latitude":"14.7000000"}` ; Flutter fait `(j['quantite'] as num)` (`M/lib/models/stock.dart:28`, `commande.dart:32`, `production.dart:28`) ; les tests tournent sous SQLite, qui renvoie des nombres | [API] (types) + [CODE] (Flutter) | En production, les écrans Stocks, Commandes et Productions du mobile lèvent une erreur de type, d'où un chargement infini. Sur le web, la comparaison de chaînes fausse le surlignage d'alerte (`"40" <= "100"` vaut faux). |
| P-08 | **Sortie de stock sans verrou ni lien avec les commandes.** | `B/…/StockController::sortie` | [CODE] | Mise à jour perdue en concurrence ; double débit si le gestionnaire enregistre aussi une sortie pour une commande déjà déduite. |

### Élevée

| # | Problème | Preuve | Niveau |
|---|---|---|---|
| P-09 | Acheteur : **impossible de commander sur mobile** | `passerCommande` jamais appelé (`M/lib/screens/acheteur/catalogue_screen.dart`) | [CODE] |
| P-10 | Capacité d'entrepôt et confirmation de réservation sans verrou ; capacité ignorant les dates | `ReservationController::store/confirmer/capaciteDisponible` | [CODE] |
| P-11 | Quantité réceptionnée sans plafond ni écart historisé ; `quantite_reelle` du lot jamais mise à jour | `ValiderMarchandiseRequest`, `validerMarchandise` | [CODE] |
| P-12 | Un lot déjà en entrepôt peut être réservé ou entré à nouveau | `StoreReservationRequest` (exclut seulement `annulee`) ; `StoreEntreeStockRequest` | [CODE] |
| P-13 | Lot modifiable après vente (culture, dates, quantités) | `ProductionController::update` | [CODE] |
| P-14 | Pas de refus de marchandise à l'arrivée | `ReservationController` | [CODE] |
| P-15 | Profils métier vides à la création par l'admin : l'entrepôt n'est jamais réservable | `AdminStoreUtilisateurRequest` ; `W/features/admin/utilisateurs` | [CODE] |
| P-16 | Web, Commandes : confirmation réussie puis échec de livraison, la commande paraît en attente | `W/features/entrepot/commandes-entrepot` (confirmation puis livraison en deux appels) | [CODE] |
| P-17 | **Mot de passe : changement ignoré** (web et mobile) ; **adresse effacée** (web) | `B/…/AuthController.php:90` ; `W/features/shared/profil/profil.component.ts:227` | [CODE] |
| P-18 | Inscription libre en gestionnaire : faux entrepôt réservable immédiatement ; `/register` non limité | `RegisterRequest`, `routes/api.php:12` | [CODE] |
| P-19 | Mobile admin : écran sans déconnexion, session persistante | `M/lib/screens/home_router.dart:77-83` | [CODE] |
| P-20 | Mobile : pas de délai d'attente HTTP ; seules les `ApiException` sont gérées dans 13 écrans, d'où des chargements infinis hors réseau | `M/lib/services/api_service.dart` ; écrans listés dans l'audit mobile | [CODE] |
| P-21 | Web : des erreurs de chargement présentées comme « aucune donnée » dans une quinzaine d'écrans. Alertes affiche « ✅ tout va bien » sur erreur. Rapport cassé pour l'admin. | `W/features/entrepot/alertes:188,219`, `rapport:243-278` | [CODE] |
| P-22 | GPS : envoi seulement écran ouvert ; positions non liées à une mission, la notification peut joindre une position d'une autre mission | `M/lib/services/position_tracking_service.dart`, `B/…/LivraisonController.php` (`LivraisonEnCoursNotification`) | [CODE] |
| P-23 | Signature Android release avec la clé de debug ; identifiant `com.agriplatform…` | `M/android/app/build.gradle.kts:23,37` | [CODE] |

### Moyenne

- **P-24 Notifications manquantes :**
  - nouvelle réservation → gestionnaire ;
  - commande annulée ou modifiée par l'acheteur → entrepôt ;
  - problème de livraison → acheteur et entrepôt ;
  - livraison terminée → entrepôt ;
  - seuil d'alerte atteint.

  Côté clients :
  - Web : clic sans navigation vers l'objet concerné.
  - Mobile : pas de liste, jamais marquées lues, interrogation toutes les 10 s même en arrière-plan.
- **P-25 Doublons :** double clic sur « Commander » donne 2 commandes [API] ; deux créations de livraison simultanées sont possibles (pas de contrainte unique sur `livraisons.commande_id`).
- **P-26 Producteur :** les prix proposés par les acheteurs sont visibles de tous les producteurs (`commandesDisponibles`), alors que la traçabilité les masque.
- **P-27 Gestionnaire :** aucune vue des livraisons de son entrepôt ; réservations « enregistrées » et commandes annulées invisibles.
- **P-28 Motifs et historiques invisibles** pour l'acheteur et le producteur ; un refus s'affiche « Annulée ».
- **P-29 Seuil d'alerte :** règles différentes entre alertes, rapport et tableau de bord ; seuil non modifiable.
- **P-30 Mobile transporteur :** démarrer et livrer sans confirmation ; liste non rafraîchie ; acheminements cachés derrière une icône.
- **P-31 Admin :** actions faites à la place d'un autre rôle non historisées ; aucune lecture de `historique_operations`.
- **P-32 Livraison :** origine et destination en texte libre ; coordonnées de destination jamais saisies sur le web, donc distance et progression jamais calculées.

### Faible

- **P-33 Numérotation** `XXX-AAAAMMJJ-xxxx` aléatoire sans nouvel essai : une collision provoque un 500.
- **P-34 Nombres :** `float` pour les quantités et les prix ; l'état `epuise` est testé par `== 0`.
- **P-35 Horodatage :** `$timestamps = false` sur commandes, stocks, livraisons et réservations, donc aucune date de modification.
- **P-36 Base :** clés étrangères non indexées sous PostgreSQL ; suppressions en cascade étendues (aucune route de suppression, risque limité).
- **P-37 Pagination :** aucune liste n'est paginée.
- **P-38 Seeder cassé :** `DatabaseSeeder` utilise `App\Models\User`, qui n'existe pas. Il n'y a donc pas de démonstration reproductible.
- **P-39 Marque :** « AgriPlatform » sur le mobile ; README Flutter par défaut.
- **P-40 Clés Reverb** par défaut versionnées dans `.env.example`.

---

## 5. Correction, refonte ou reconstruction

| | A. Correction ciblée | **B. Refonte structurée** | C. Reconstruction |
|---|---|---|---|
| Principe | Corriger P-01 à P-40 un par un | Garder l'infrastructure fiable ; **redéfinir les processus comme des machines à états** ; refaire les écrans par tâche ; décider du rôle du mobile | Réécrire site et mobile (et éventuellement le backend) |
| Garde | Tout | Modèle de données, authentification, rôles, verrous, tests, traçabilité, suivi, composants d'interface | Le schéma et les tests comme spécification |
| Avantages | Rapide ; risque faible à chaque étape | Traite la cause (transitions implicites dispersées dans les contrôleurs) ; réutilise ~70 % du code | Cohérence maximale |
| Limites | Laisse la cause en place : chaque nouvelle règle ajoute un cas oublié. C'est le mode de travail des PR #1 à #6, qui a produit les symptômes actuels | Demande de trancher le périmètre (§8) avant de coder | Perte du travail fiable (sessions, sécurité, suivi GPS) ; long ; régressions certaines |
| Données | Aucune migration lourde | Migrations additives (prix, horodatage, statuts, index, contrainte unique) avec reprise des données existantes | Migration complète des données |
| Coût relatif | 1 | 2 à 3 | 5 et plus |
| Risque de mise en production | Faible par étape, élevé à terme | Modéré, maîtrisé par lots | Élevé |

**Recommandation : scénario B.** Le backend n'a pas besoin d'être reconstruit :
- son modèle de données correspond au cahier ;
- ses contrôles d'accès sont corrects et testés ;
- les opérations les plus sensibles sont déjà atomiques.

Ce qui manque est structurel mais localisé :
1. Les processus n'ont **pas de définition unique** des états et transitions. Chaque contrôleur décide seul, d'où les impasses (P-02, P-14), les états jamais atteints (P-03) et les verrous oubliés (P-05, P-08, P-10).
2. Le **périmètre** a dérivé du cahier sans décision explicite.
3. **Deux clients** implémentent chacun une partie différente des fonctions.

Une reconstruction ne réglerait aucun de ces trois points sans les décisions métier du §8. Elle détruirait en revanche du code éprouvé.

Pour le mobile, je recommande une **refonte de son périmètre** plutôt qu'une réécriture : le recentrer sur les rôles prévus par le cahier, et le rendre fiable (types, erreurs, permissions, GPS). La version installable du site couvre déjà correctement les usages de consultation sur téléphone. Voir Q4.

---

## 6. Architecture cible recommandée

### 6.1 Principe : une définition unique de chaque processus

Dans le backend, chaque processus (Production, Réservation, Commande, Livraison) devient un **service de transitions** : une classe par processus, par exemple `app/Domain/Commande/TransitionsCommande.php`. Ce service porte :
- le tableau des transitions : état de départ → action → état d'arrivée, rôle autorisé, conditions ;
- **un seul point d'exécution**, qui :
  - ouvre une transaction ;
  - verrouille la ligne ;
  - revérifie l'état ;
  - applique les effets (stock, capacité, statut lié) ;
  - écrit l'historique ;
  - programme les notifications **après validation**, en file d'attente, sans jamais faire échouer l'action.

Les contrôleurs ne font plus que valider l'entrée et appeler une transition. Les états possibles et les actions autorisées sont **renvoyés par l'API** (`actions_possibles`) : le web et le mobile n'ont plus à recoder les règles. C'est la principale source de divergence actuelle.

Ce n'est pas un moteur générique : quatre classes simples, testées par des tests de tableau de transitions. Elles réutilisent ce qui existe : `HistoriqueOperation`, les verrous de `CommandeController::confirmer` et les notifications existantes.

### 6.2 États cibles (proposition, à valider au §8)

**Commande**

```
en_attente ─confirmer(GE)─▶ confirmee ─livraison démarrée─▶ en_livraison ─livrée─▶ livree
    │ annuler(AG) / refuser(GE, motif)        │ annuler(GE, motif, sans livraison démarrée → stock recrédité)
    ▼                                          ▼
 annulee / refusee                           annulee
```

**Livraison**

```
assignee ─accepter(T)─▶ acceptee ─démarrer(T)─▶ en_cours ─livrer(T)─▶ livree ─confirmer(GE/AG ?)─▶ cloturee
   │ refuser(T) → retour au GE                    │ signaler(T, description) → probleme
   │ réassigner(GE)                                              ├─ reprendre(T/GE) → en_cours
                                                                 └─ échec(GE) → commande à réassigner ou annuler (Q8)
```

**Réservation**

```
en_attente ─▶ confirmee ─▶ transport_assigne ─démarrer(T)─▶ en_transit ─▶ arrivee ─réceptionner(GE)─▶ receptionnee
                                                                       └─ refuser_reception(GE, motif) ─▶ rejetee
```

**Production**

```
declaree ─▶ (réservée) ─▶ en_stock ─▶ vendue_partiellement ─▶ epuisee      annulee (si inutilisée)
```

Champs figés dès la mise en stock.

### 6.3 Données (migrations additives, sans suppression)

- `stocks.prix_unitaire`, si Q5 est validée. Le prix de la commande est alors **calculé par le serveur**.
- `created_at` et `updated_at` sur les tables métier.
- Contrainte unique `livraisons.commande_id` ; index sur toutes les clés étrangères.
- `decimal` pour quantités et prix, avec **casts** dans les modèles. Cela corrige P-07 et P-34.
- `livraisons.motif_probleme` ; `positions_gps.livraison_id`.
- `reservations.quantite_recue`, l'écart étant historisé.
- Statuts `refusee` (commande) et `rejetee` (réservation).
- Numérotation séquentielle par année.

### 6.4 Infrastructure

- Notifications en file d'attente (`ShouldQueue`) : base de données d'abord, diffusion temps réel optionnelle.
  - Sur Render : `BROADCAST_CONNECTION=log` tant que Reverb n'est pas déployé.
  - Ou un service Reverb dédié, avec des secrets propres.
  - Interrogation de secours côté clients.
- Clé d'idempotence sur les créations (commande, réservation, livraison) contre les doubles envois.
- Tests backend exécutés **aussi sur PostgreSQL** dans la CI. Les défauts P-07 et de concurrence ne se voient pas sous SQLite.

### 6.5 Répartition backend / web / mobile

| | Backend | Web (installable) | Mobile Flutter |
|---|---|---|---|
| Producteur | règles, transitions | complet | **complet** (usage principal selon le cahier) |
| Gestionnaire | idem | **complet** | consultation et actions rapides (confirmer, réceptionner) — Q4 |
| Acheteur | idem | **complet** | catalogue, **commande**, suivi |
| Transporteur | idem | missions (sans GPS) | **complet + GPS** |
| Admin | supervision, historique | **complet** | non (déconnexion propre) |

### 6.6 Écrans cibles : organisés par tâche

Chaque rôle arrive sur **« À faire »**, la liste des objets qui attendent son action, alimentée par `actions_possibles`. S'y ajoutent ses listes par statut et une fiche détaillée par objet, avec chronologie, historique et actions. Les notifications ouvrent directement la fiche concernée.

Il n'y a plus de saisie d'identifiant brut : sélection du lot ou du stock. Les erreurs sont distinguées de l'état vide sur tous les écrans.

---

## 7. Plan de réalisation priorisé

Chaque lot fait l'objet d'une branche, d'une PR et de tests, et peut être déployé et annulé séparément.

| Lot | Contenu | Dépend de | Tests de sortie |
|---|---|---|---|
| **0. Sécurisation immédiate** (avant toute refonte) | P-01 : notifications en file, ou `BROADCAST_CONNECTION=log` sur Render. P-06 : permission INTERNET. P-07 : casts numériques. P-05, P-08, P-10 : verrous. P-17 : mot de passe et adresse. P-19 : déconnexion admin mobile | — | tests PostgreSQL ; reproduction 500 → 200 ; double réception refusée ; APK installé sur un appareil |
| **1. Décisions métier** | Réponses au §8 ; mise à jour du cahier des charges | — | document validé |
| **2. Services de transitions backend** | Commande et livraison d'abord (P-02, P-03, P-04, P-25), puis réservation et production (P-11 à P-14) ; `actions_possibles` ; historique partout ; notifications complètes (P-24) | 0, 1 | tests de tableau de transitions (chaque transition autorisée et interdite par rôle) ; tests de concurrence PostgreSQL dans la CI |
| **3. Migrations additives et reprise** | §6.3 ; script de reprise (commandes « livrées » déduites des livraisons, prix existants conservés) | 2 | migration à blanc sur une **copie** de la base de production ; comptes de contrôle avant et après |
| **4. Web par tâche** | « À faire », fiches, erreurs, admin supervision | 2 | tests de composants ; **parcours Playwright multi-comptes** (5 rôles) |
| **5. Mobile** | Périmètre Q4, gestion d'erreurs et délais, GPS en arrière-plan si Q9, notifications, signature release | 2 | tests de widgets ; **APK installé sur au moins un Android réel** ; même parcours que le web |
| **6. Recette** | Parcours complets avec comptes de test sur un environnement de préproduction | 3, 4, 5 | critères du §10 |

**Protection des données et retour arrière :**
- Sauvegarde de la base de production (export PostgreSQL Render) avant chaque migration.
- Migrations uniquement additives, avec `down()` testé.
- Une préproduction Render alimentée par une copie anonymisée.
- Déploiement par lot. En cas d'échec : redéploiement du commit précédent (Render « Rollback »), puis `migrate:rollback` du lot si nécessaire.
- Aucun test sur la base de production.

---

## 8. Questions métier à trancher (avant le lot 2)

1. **Périmètre :** le circuit réservation → acheminement → réception, absent du cahier, est-il validé avec votre encadreur, et doit-il être documenté dans le mémoire ? Si non, le producteur doit-il enregistrer lui-même une entrée de stock (CU du cahier) ?
2. **Accepter une mission (UC2 Logistique) :** le transporteur doit-il accepter ou refuser une mission avant qu'elle soit ferme ? Que se passe-t-il en cas de refus (retour au gestionnaire) ?
3. **Confirmer la livraison (UC6) :** qui clôture la livraison : le transporteur, le gestionnaire, l'acheteur (réception) ? Faut-il une confirmation de réception par l'acheteur ?
4. **Mobile :** confirmez-vous la répartition du cahier (§3) ? Le gestionnaire doit-il pouvoir agir sur mobile (confirmer, réceptionner) ou seulement consulter ? L'acheteur doit-il pouvoir commander sur mobile ?
5. **Prix :** le prix est-il fixé par le producteur, par l'entrepôt, négocié, ou simplement indicatif ? Prix au kilo ou total ?
6. **Commandes multiples sur un même stock :** une commande en attente doit-elle réserver la quantité, pour que deux acheteurs ne commandent pas le même stock ?
7. **Inscription :** les gestionnaires d'entrepôt et les transporteurs doivent-ils être validés par l'administrateur avant d'être actifs ?
8. **Problème de livraison :** quelles issues : reprise, changement de transporteur, retour à l'entrepôt avec remise en stock, annulation de la commande ? Qui décide ?
9. **GPS :** le suivi doit-il continuer téléphone verrouillé (service en arrière-plan, consentement explicite, batterie) ?
10. **Réception :** si la quantité reçue diffère de la réservation, faut-il une tolérance, un accord du producteur, un statut « réception partielle » ?
11. **Un lot sur plusieurs entrepôts** (règle 5 du cahier) : un même lot peut-il être réparti ? Faut-il des réservations partielles ?
12. **Traçabilité :** qui peut consulter le parcours d'un lot : tout utilisateur connecté, seulement les acteurs du lot, ou le public (QR code) ?
13. **Producteur et commandes :** le producteur doit-il pouvoir agir sur une commande (accepter un prix, préparer) ou seulement être informé ?
14. **Sorties de stock manuelles :** quels motifs légitimes (perte, avarie, retrait par le producteur) ? Doivent-elles être interdites pour les commandes ?

---

## 9. À préserver absolument

- Authentification Sanctum persistante et révocable ; intercepteur web ; stockage chiffré du jeton mobile ; révocation au blocage ; protection du dernier administrateur (PR #1 et #5).
- Contrôles d'accès serveur par rôle, propriétaire et périmètre de l'entrepôt, avec leurs tests.
- Confirmation de commande atomique (verrou, revérification, déduction) ; annulation avec remise en stock.
- Corrections de réservation (assigner, réassigner, retirer, refuser, remettre en attente, annuler) avec historique `historique_operations` (PR #6).
- Traçabilité de lot avec masquage des données sensibles ; chronologie web et mobile.
- Suivi de livraison (carte, position ancienne, heure serveur, pause en arrière-plan), web et mobile.
- Espace « Mes missions » web, confirmations explicites, actualisation au retour au premier plan.
- Mise en page responsive et tests de débordement Flutter ; manifeste installable.
- Les 17 fichiers de tests backend, les tests Angular et Flutter : base de non-régression.

---

## 10. Critères d'acceptation du résultat final

1. **Parcours complets multi-comptes** automatisés (Playwright web, test d'intégration Flutter), sur PostgreSQL, sans intervention manuelle en base, pour :
   - récolte → réception → commande → livraison → livrée ;
   - refus, annulations, problème de livraison avec issue.
2. **Aucune impasse :** pour chaque état non final, le test de transitions prouve qu'au moins une action est possible pour au moins un rôle. Chaque état final est explicitement listé.
3. **Aucun double effet :** double clic, requête répétée et requêtes simultanées (tests PostgreSQL) ne créent ni ne débitent jamais deux fois.
4. **Aucune action réussie ne répond une erreur.** Une notification qui échoue n'annule ni ne masque jamais l'action.
5. **Bon destinataire :** pour chaque transition, la liste des notifications envoyées est testée (qui reçoit, qui ne reçoit pas).
6. **Cohérence des clients :** les actions proposées par le web et le mobile viennent de `actions_possibles`. Une même fiche affiche le même statut et les mêmes actions sur les deux.
7. **Erreurs visibles :** sur chaque écran, une erreur réseau ou serveur affiche un message et une action « Réessayer », jamais une liste vide ni un chargement infini (tests dédiés).
8. **Sécurité :** chaque route refuse un autre rôle et un autre propriétaire (test par route) ; inscription conforme à Q7 ; mot de passe réellement modifiable, l'ancien refusé.
9. **Mobile :** APK release signé, installé sur au moins un Android réel, parcours transporteur et producteur réussis ; GPS conforme à Q9.
10. **Production :** déploiement d'un lot sur la préproduction, puis en production avec sauvegarde préalable, contrôle des comptages avant et après, et retour arrière testé une fois.

---

## Annexe — Actions de votre part, sans attendre

1. **Journaux Render (backend) :** rechercher `Pusher error` ou `BroadcastException`. Vérifier si `BROADCAST_CONNECTION` est défini dans les variables d'environnement Render, et donner sa valeur (ce n'est pas un secret). Cela confirmera ou écartera P-01.
2. Vérifier `SANCTUM_EXPIRATION` sur Render (absente ou vide attendue).
3. Répondre aux questions du §8, même partiellement : Q1, Q3, Q4 et Q5 conditionnent le reste.
