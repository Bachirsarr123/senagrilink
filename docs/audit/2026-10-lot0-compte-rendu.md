# Lot 0 — Compte rendu (sécurisation immédiate)

*Branche `claude/senagrilink-full-audit-mjty0f`, à partir de `main` (`20ab14b`) et de l'audit (`bab9fa5`).*
*Rien n'a été fusionné ni déployé. Aucune donnée ni variable de production n'a été modifiée. Aucune migration n'a été lancée sur Render.*

Références : [audit général](2026-10-audit-general.md), numéros P-xx.

## 1. Problèmes corrigés et causes

| # | Problème | Cause exacte | Correction | Reproduit avant ? |
|---|---|---|---|---|
| P-01 | Action enregistrée mais réponse 500 si Reverb est absent | Canal `broadcast` synchrone ; le client Pusher lève `BroadcastException`, propagée jusqu'au contrôleur | Canal `broadcast` tolérant (`DiffusionToleranteChannel`), enregistré dans `AppServiceProvider` : l'échec est journalisé (`warning`). La notification en base, écrite avant, est conservée et les destinataires suivants sont notifiés. | Oui : test (`BroadcastException`) et API locale (500 avec commande créée) |
| P-25 | Doublons quand l'utilisateur recommence | Aucune protection : chaque `POST` crée un enregistrement | En-tête `Idempotency-Key`, middleware `idempotence` sur les 7 routes de création. Une même soumission renvoie la réponse d'origine ; un échec n'est pas mémorisé. Table additive `requetes_idempotentes`, purgée après 24 h. Clés générées par le web et le mobile. | Oui : sonde API (2 commandes au double envoi) |
| P-05 | Double réception d'une marchandise, donc double stock | `validerMarchandise` vérifiait le statut hors verrou | `lockForUpdate` + revérification | Oui : test PostgreSQL en concurrence (échoue sur l'ancien code) |
| P-08 | Sortie de stock : mise à jour perdue | Lecture puis écriture sans verrou | Idem | Oui : idem |
| P-10 | Capacité d'entrepôt dépassée, double confirmation | Calcul de capacité et confirmation sans verrou | Verrou sur l'entrepôt puis sur la réservation | Oui : idem |
| — | Deux livraisons pour une commande | Unicité vérifiée en PHP seulement | Verrou sur la commande + revérification | Oui : idem |
| — | Arrivée signalée ou livraison mise à jour par un transporteur qui vient d'être remplacé | Propriété vérifiée avant, écriture après | Revérification sous verrou (403) | Oui : idem |
| — | Modification de commande appliquée à une commande en cours de confirmation | Statut vérifié hors verrou | Verrou commande + stock | Non reproduit en concurrence ; même schéma que les cas ci-dessus |
| P-07 | Mobile inutilisable sur les données PostgreSQL | `pdo_pgsql` renvoie `float`/`decimal` en chaînes ; Flutter faisait `as num` | Backend : casts `float` dans 9 modèles. Mobile : conversion tolérante (`nombre()`) dans les modèles, la carte et la chronologie | Oui : capture réelle (`'70.5'`) ; anciens modèles Flutter : `String is not a subtype of num` |
| P-06 | APK release sans accès réseau | `INTERNET` absente du manifeste principal | Permission ajoutée | Vérifié dans le code seulement (voir §6) |
| P-09 | Acheteur ne peut pas commander sur mobile | Aucun écran n'appelait `passerCommande` | Bouton « Commander » et formulaire (mêmes champs et contrôles que le web) | Oui : test d'écran (échoue sur l'ancien code) |
| P-19 | Administrateur bloqué sur mobile | Écran sans déconnexion, session persistante | Message « administration sur le site web » + bouton « Se déconnecter » | Oui : idem |
| P-20 | Chargements infinis sur mobile | Pas de délai d'attente ; erreurs réseau, `FormatException` et réponses HTML non converties | Délai de 60 s ; réseau, délai et réponse non JSON deviennent une `ApiException`, que tous les écrans savent afficher | Oui : test d'écran (échoue sur l'ancien code) |
| P-17 | Mot de passe ignoré ; adresse effacée (web) | Champ non traité par le backend ; formulaire web initialisé à vide | Changement réel avec **mot de passe actuel exigé** (sécurité standard) et révocation des autres sessions ; adresse préremplie | Oui (lecture du code) ; tests ajoutés |
| P-16 | Web : livraison en échec après confirmation, la commande paraît bloquée | Le nouvel essai reconfirmait (422) | Le nouvel essai ne crée que la livraison (même clé) ; message explicite ; liste rechargée | Oui : test de composant |
| P-21 (part.) | Alertes : « ✅ tout va bien » sur erreur | Erreur avalée | Message d'erreur + « Réessayer » | Oui : test de composant |

**Effet secondaire traité :** l'écran de suivi mobile distinguait la coupure réseau par le type d'exception. Il traite maintenant l'`ApiException` sans code comme une coupure : la dernière position reste affichée. Détecté par un test existant, qui passe de nouveau.

## 2. Fichiers modifiés

- **Backend :**
  - Notifications : `app/Notifications/Channels/DiffusionToleranteChannel.php` (nouveau), `app/Providers/AppServiceProvider.php`.
  - Idempotence :
    - `app/Http/Middleware/Idempotence.php` (nouveau) ;
    - `database/migrations/2025_01_01_000018_create_requetes_idempotentes_table.php` (nouvelle, additive, `down()` = suppression de la table) ;
    - `bootstrap/app.php`, `routes/api.php`.
  - Verrous : `app/Http/Controllers/Api/{Reservation,Livraison,Commande,Stock}Controller.php`.
  - Types numériques : 9 modèles (`Stock`, `Commande`, `Production`, `Reservation`, `Entrepot`, `Livraison`, `Producteur`, `Transporteur`, `AcheteurGros`).
  - Mot de passe : `AuthController.php`, `Requests/UpdateProfileRequest.php`.
  - Tests :
    - nouveaux : `NotificationResilienceTest`, `IdempotenceTest`, `TypesNumeriquesJsonTest`, `ChangementMotDePasseTest` ;
    - étendu : `ConcurrencePostgresTest`, avec 5 nouveaux cas.
- **Web :**
  - `shared/idempotence/cle-soumission.ts` (nouveau) et `core/services/api.service.ts`.
  - Composants : catalogue, productions, réservations du producteur, stocks, utilisateurs (admin), commandes de l'entrepôt, alertes, profil.
  - `features/lot0.spec.ts` (nouveau).
- **Mobile :**
  - `android/app/src/main/AndroidManifest.xml` ;
  - `lib/services/api_service.dart` ;
  - `lib/utils/nombres.dart` (nouveau), `lib/utils/geo.dart`, `lib/utils/chronologie.dart` ;
  - les 3 modèles ;
  - `lib/screens/acheteur/formulaire_commande.dart` (nouveau), `catalogue_screen.dart`, `suivi_livraison_screen.dart` ;
  - `home_router.dart`, `nouvelle_recolte_screen.dart`, `planifier_ventes_screen.dart`, `profil_screen.dart` ;
  - Tests :
    - nouveaux : `parcours_lot0_test`, `nombres_postgresql_test`, `donnees_postgresql_reelles_test` (avec la capture `fixtures/api_postgresql.json`), `configuration_android_test` ;
    - complété : `api_service_test`.

## 3. Tests exécutés et résultats

| Type | Où | Résultat |
|---|---|---|
| Automatisés backend, SQLite | `php artisan test` | 143 réussis, 8 ignorés (tests PostgreSQL seulement) |
| Automatisés backend, **PostgreSQL 16** local, concurrence incluse | idem avec `DB_CONNECTION=pgsql` | **151 réussis** |
| Preuve des reproductions | mêmes tests sur l'ancien code (fichiers mis de côté temporairement) | 5 tests de concurrence échouent ; `TypesNumeriquesJsonTest` échoue (`'80.5'`) |
| Automatisés Angular | `ng test` | 74 réussis |
| Compilation web | `tsc`, `ng build` | OK |
| Automatisés Flutter | `flutter test` | 197 réussis |
| Analyse Flutter | `flutter analyze` | 4 remarques de style préexistantes, aucune erreur |
| Preuve des reproductions mobile | nouveaux tests sur l'ancien `lib/` | 6 échecs (écrans, manifeste) et 3 échecs sur la capture PostgreSQL |
| **API réelle**, PostgreSQL local + Reverb configuré mais absent (comme Render) | sonde Python, 4 comptes | 7/7 : commande 201 au lieu de 500 ; nouvel essai rejoué, 1 seule commande et 1 seule notification par destinataire ; confirmation 200, stock débité une fois ; confirmation répétée 422, stock inchangé |
| **Navigateur** (Chromium, site Angular local, même API) | Playwright | 5/5 : réponse perdue après enregistrement, erreur affichée ; le nouvel essai affiche le succès, **sans doublon**, même clé |
| Appareil physique | — | **Non réalisé** (aucun téléphone ni SDK Android disponible) |

## 4. Problèmes toujours présents

- **Dépendant d'une décision (§5) :**
  - P-02 (livraison « probleme » sans issue) ;
  - P-03 (commande jamais « livrée ») ;
  - P-04 (prix saisi par l'acheteur) ;
  - P-11, P-12, P-13, P-14 (réception, lots, refus de marchandise) ;
  - P-18 (inscription libre en gestionnaire) ;
  - P-22 (GPS en arrière-plan) ;
  - P-26 (prix visibles des autres producteurs).
- **Corrigeables techniquement, laissés pour les lots suivants :**
  - P-15 (profils vides créés par l'admin) ;
  - P-21 (erreurs avalées dans une douzaine d'autres écrans web) ;
  - rapport admin cassé ;
  - P-24 (notifications manquantes) ;
  - P-27 à P-32 ;
  - P-33 (collision de numéros) ;
  - P-36 et P-37 ;
  - P-38 (seeder cassé) ;
  - P-39 et P-40 ;
  - mise à jour du profil mobile non reflétée avant reconnexion ;
  - limitation de débit sur `/register`.
- **Contrainte d'unicité `livraisons.commande_id` en base :** non ajoutée. La migration échouerait si la production contient déjà des doublons, et je ne peux pas le vérifier. Le verrou applicatif protège dès maintenant.
- **P-23 :** la signature release Android utilise toujours la clé de debug. Il faut votre keystore, que je ne dois pas créer à votre place.
- **CI :** les tests PostgreSQL et de concurrence ne tournent pas dans Jenkins (il faudrait un service PostgreSQL).

## 5. Décisions métier encore nécessaires

Questions Q1 à Q14 de l'audit, en particulier :
- **Q3 et Q8** (clôture d'une livraison, issue d'un problème) : bloquent P-02 et P-03.
- **Q5** (prix) : bloque P-04.
- **Q10, Q11, Q14** (réception, lots multi-entrepôts, sorties manuelles).
- **Q7** (validation des gestionnaires et transporteurs).
- **Q9** (GPS en arrière-plan).

Une précision sur le mot de passe : j'ai exigé le mot de passe actuel, ce qui est une règle de sécurité standard. J'ai aussi déconnecté les autres sessions après un changement, ce que vous aviez indiqué comme légitime. Dites-moi si vous préférez l'inverse.

## 6. Risques résiduels et vérification en préproduction

**Risques :**
- **Notifications :** P-01 reste une hypothèse forte pour la production, tant que les journaux Render ne sont pas consultés. Si `BROADCAST_CONNECTION` y est déjà neutralisé, la correction est sans effet visible mais inoffensive. Les diffusions temps réel restent absentes tant que Reverb n'est pas déployé : le site et le mobile ne voient les notifications qu'au rechargement ou par l'interrogation périodique du mobile.
- **Idempotence :** seuls les clients mis à jour envoient la clé. Une ancienne version de l'APK reste sans protection, mais fonctionne comme avant.
- **Types numériques :** les quantités saisies auparavant sont inchangées en base ; seul leur format JSON change. Le web les lisait déjà correctement.
- **Mobile :** le délai de 60 s peut se déclencher pendant un réveil Render très long ; l'utilisateur voit alors « Le serveur ne répond pas » et peut réessayer.

**Étapes de vérification en préproduction** (service Render séparé, avec une copie de la base) :
1. Sauvegarder la base de production. Créer la préproduction sur une **copie**, jamais sur la base réelle.
2. Déployer la branche ; `start.sh` lance `migrate`. Vérifier que seule `2025_01_01_000018` s'applique (`php artisan migrate:status`).
3. Avec un compte acheteur de test : passer une commande. Attendu : message de succès et aucune ligne `Pusher error` en erreur dans les journaux, seulement un avertissement « Diffusion temps réel impossible ».
4. Couper le réseau (mode avion) juste après « Confirmer la commande », le rétablir, recommencer : une seule commande dans « Mes commandes ».
5. Gestionnaire : confirmer cette commande. Attendu : succès et stock diminué une seule fois.
6. Construire l'APK (CI GitHub Actions) et l'installer sur un Android réel :
   - connexion avec chaque rôle ;
   - listes des stocks, des productions et des commandes affichées (P-07) ;
   - commande depuis le catalogue (P-09) ;
   - administrateur : bouton « Se déconnecter » (P-19) ;
   - mode avion : message au lieu d'un chargement infini (P-20).
7. Profil : changer le mot de passe (avec l'actuel). L'ancien est refusé, le nouveau accepté, et l'autre appareil est déconnecté. L'adresse est conservée.
8. **Retour arrière :** voir §7.2, qui remplace la consigne `migrate:rollback --step=1` donnée initialement.

---

## 7. Revue technique avant fusion (20 octobre 2026)

### 7.1 Idempotence : comportement exact

Clé = en-tête `Idempotency-Key` (`[A-Za-z0-9_-]{8,100}`), propre à un utilisateur (unicité `utilisateur_id` + `cle`). Empreinte = SHA-256 de « méthode + chemin + corps brut ».

| Situation | Réponse | Effet en base |
|---|---|---|
| Première requête | Traitement normal | Création ; réponse 2xx mémorisée |
| Même clé, même contenu, réponse mémorisée | **Réponse d'origine** (même code, même corps) + `Idempotent-Replayed: true` | Aucune nouvelle ligne, aucune notification |
| Même clé, **contenu différent** | **422** « Cette opération a déjà été enregistrée avec d'autres valeurs. Actualisez la page avant de recommencer. » | Rien. L'enregistrement d'origine est intact ; la seconde saisie n'est pas créée, l'utilisateur en est informé. |
| Même clé, première requête encore en traitement | **409**, code `requete_en_cours` | Rien |
| Même clé, première requête interrompue depuis plus de 2 min (serveur redémarré) | **409**, code `tentative_interrompue` : vérifier la liste puis rouvrir le formulaire | Rien. Jamais de retraitement automatique : on ne sait pas si l'opération a abouti. |
| Première requête en échec (4xx/5xx) | — | Trace supprimée : la saisie corrigée peut réessayer avec la même clé |
| Même clé, autre utilisateur | Traitement normal et indépendant | — |
| Sans en-tête | Comportement d'avant le lot | — |

**Clients :** une clé par **ouverture de formulaire**, conservée pour les nouveaux essais et renouvelée après un succès.
- Web, 7 formulaires : commande, récolte, réservation, entrée de stock, sortie de stock, livraison, création d'utilisateur.
- Mobile : commande, récolte (seuls formulaires de création du mobile).

Rouvrir le formulaire équivaut à une nouvelle saisie.

**Limite connue :** les actions sans clé (changement de statut d'une livraison, arrivée, annulation, modification de commande) sont protégées par les verrous et les contrôles d'état : aucun double effet possible. Un nouvel essai après un délai dépassé peut toutefois afficher « transition non autorisée » alors que la première tentative a réussi. Le message est trompeur, sans incohérence de données ; l'utilisateur voit l'état réel en actualisant.

### 7.2 Migrations et retour arrière (simulés sur PostgreSQL 16 local)

- **Dans `main` :** `000001` à `000017`. `000017` (`historique_operations`) vient de la PR #6, fusionnée le 20 octobre.
- **Ajoutée par le lot 0 :** `000018_create_requetes_idempotentes_table` seulement (table technique ; `down()` = suppression de cette table).

**Exécuté au déploiement** (`start.sh` lance `migrate --force`) :

| État réel de la base Render | Migrations exécutées | Lot (*batch*) |
|---|---|---|
| A — PR #6 déjà déployée | `000018` seule | nouveau lot |
| B — PR #6 jamais déployée | `000017` **et** `000018` | **le même lot** |

Je ne peux pas savoir lequel des deux est réel sans accès à Render. Il faut lancer `php artisan migrate:status` dans le shell Render avant toute opération.

**Retour arrière, simulé dans les deux cas :**

| Commande | Cas A | Cas B |
|---|---|---|
| `migrate:rollback --path=database/migrations/2025_01_01_000018_create_requetes_idempotentes_table.php --force` | annule `000018` seule ✅ | annule `000018` seule ✅ |
| `migrate:rollback --step=1 --force` | annule `000018` seule | annule `000018` seule |
| `migrate:rollback --force` (sans option) | annule `000018` seule | ⚠️ **annule aussi `000017` : la table `historique_operations` et ses données sont supprimées** |

**Procédure confirmée :**
1. Redéployer le commit précédent (Rollback Render). L'ancien code ignore la table `requetes_idempotentes` : **aucun retour de migration n'est nécessaire**.
2. Seulement si l'on veut supprimer la table technique : `php artisan migrate:status` (vérifier que `000018` est la dernière exécutée), puis la commande `--path=…000018…` ci-dessus.
3. **Ne jamais lancer `migrate:rollback` sans `--path`.**

La table ne contient que des traces techniques de moins de 24 h ; sa suppression ne fait perdre aucune donnée métier.

### 7.3 Application Android : vérifiée sur l'APK release produit par la CI

- Nouveau workflow `.github/workflows/mobile-verification.yml` : construction release, lecture du manifeste fusionné par `aapt2`, certificat affiché.
  - Il **ne publie rien**, contrairement à `mobile-apk.yml` qui remplace la release `mobile-latest`.
  - L'APK est conservé 7 jours comme artefact privé.
- Exécution n° 1 (commit `868e98d`) : réussie. Sortie `aapt2 dump permissions` :
  - `uses-permission: name='android.permission.INTERNET'` ✅
  - `ACCESS_FINE_LOCATION` et `ACCESS_COARSE_LOCATION`.
- **Signature :** `CN=Android Debug`. La clé de debug est utilisée, problème préexistant (P-23). Je n'ai ajouté aucune clé ; il faudra votre keystore de production.
- **Reste à vérifier sur un appareil réel :** installation, connexion, appels réseau effectifs.

### 7.4 Précision des nombres

- **Colonnes PostgreSQL :** `double precision` pour les quantités, prix et capacités ; `numeric(10,7)` pour les coordonnées.
- **Conversion :** le cast `float` produit le même double IEEE 754 que celui stocké, donc aucune perte. Les coordonnées (10 chiffres significatifs) tiennent dans un double. JSON au plus court exact (`serialize_precision=-1`) : `1499999.99`, `14.6937001`, `1234567.891` relus à l'identique.
- **Arithmétique du stock :** identique avant et après (`0.3 − 0.1 − 0.2` donne `-2.78e-17` dans les deux cas), car PHP convertissait déjà les chaînes en `float`.
- **Préexistant, non introduit par le lot (P-34) :** les montants et quantités sont stockés en `float`, d'où ces résidus (et un stock qui n'atteint jamais exactement 0). La correction suppose une migration en `decimal`, à planifier.
- **Flutter :** `double.tryParse` donne le même double que la valeur JSON.

### 7.5 Défauts trouvés à la revue et corrigés (avec test)

| Défaut | Correction | Test |
|---|---|---|
| Clé bloquée 24 h si le serveur est interrompu en cours de requête | 409 `tentative_interrompue` après 2 min, sans retraitement | `IdempotenceTest` |
| Web : 3 formulaires gardaient la même clé toute la page (422 sur une nouvelle saisie après une réponse perdue) | Une clé par ouverture | `lot0.spec` |
| Mobile : erreurs TLS (`HandshakeException`) non converties, chargement infini | `on IOException` | `api_service_test` |
| Mobile : un 200 non JSON (portail Wi-Fi captif) arrêtait le suivi GPS | ApiException sans code, traitée comme une coupure | `api_service_test` |
| Mobile : un 401 HTML d'un proxy déconnectait l'utilisateur | Invalidation seulement sur une réponse JSON de l'API | `api_service_test` |
| Mobile : déconnexion annulée si l'application est fermée pendant l'appel réseau | Session locale effacée avant l'appel | `api_service_test` |
| Confirmation de réservation : capacité lue sur l'entrepôt chargé avant le verrou | Entrepôt verrouillé utilisé | `ConcurrencePostgresTest` (échoue sur l'ancienne version) |
| Changement de mot de passe : erreur 500 si la session n'est pas un jeton personnel | Garde sur `PersonalAccessToken` | `ChangementMotDePasseTest` (échoue sur l'ancienne version) |
| Web : réponse de confirmation perdue, le nouvel essai restait bloqué ; message erroné sur coupure | Reprise sur `statut_actuel: confirmee` ; message nuancé | `lot0.spec` |
| Idempotence sous PostgreSQL sans transaction englobante (chemin de production) non testée | — | `ConcurrencePostgresTest` |

### 7.6 Résultats finaux

| Suite | Résultat |
|---|---|
| Laravel, SQLite | 145 réussis, 10 ignorés (PostgreSQL seulement) |
| Laravel, PostgreSQL 16 (concurrence incluse) | **155 réussis** |
| Angular | 76 réussis ; compilation OK |
| Flutter | 207 réussis ; analyse sans erreur (4 remarques de style préexistantes) |
| APK release (CI GitHub) | construit ; INTERNET présente dans le manifeste fusionné |

---

## 8. Vérifications avant fusion : CI, publication Android, effets d'une fusion (20 octobre 2026)

### 8.1 CI sur le dernier commit
- Dernier commit de la branche : `442face734f3550b493dd8e35e6029cfe8a9c41e`.
- **Une seule exécution porte sur ce commit** : la n° 3 (`39478193071`), du workflow « Vérification APK release ». Elle est **réussie à toutes les étapes** :
  - construction release ;
  - `aapt2` : `android.permission.INTERNET` présente ;
  - certificat affiché ;
  - artefact déposé.
- L'exécution n° 2 (`39477435555`, réussie) portait sur `cb9e6b6`, qui n'est pas le dernier commit. La n° 1 (`39476932218`, réussie) portait sur `868e98d`.
- Aucun autre workflow GitHub ne teste le backend ou le web. Leurs tests ont été exécutés localement (§7.6). Jenkins n'est pas branché sur GitHub Actions.

### 8.2 Publication Android existante (`mobile-apk.yml`)
- **Déclenchement :** tout push sur `main` touchant `mobile-app/**` (ou lancement manuel). Il construit `flutter build apk --release` et **remplace** la release publique `mobile-latest`.
- **Signature :** `android/app/build.gradle.kts` signe la release avec `signingConfigs.getByName("debug")`. Aucun keystore n'est configuré dans le dépôt, et aucun secret n'est utilisé par le workflow. Chaque runner GitHub crée donc **une nouvelle clé de debug aléatoire** à chaque construction.
- **APK actuellement publié** (release `mobile-latest` du 19 octobre, construit depuis `7e7aa20`, 0 téléchargement enregistré). Analysé en lecture seule, empreinte du fichier `244500ff…` identique à celle de GitHub :
  - signature v2, certificat `CN=Android Debug`, créé le 19 octobre à 15 h 33 (pendant la construction), SHA-256 `d2f408cc…` ;
  - **`android.permission.INTERNET` absente** : l'APK distribué ne peut joindre aucune API (P-06 confirmé sur le fichier réellement publié).
- **Builds de la branche :** certificats `2354d22b…` (n° 1) et `01f7a5d6…` (n° 3), tous différents.
  - L'analyseur utilisé pour l'APK publié a été validé sur l'APK de la n° 3 : même empreinte et mêmes permissions que celles rapportées par `apksigner` et `aapt2`.
- **Conséquence :** l'APK publié et toute prochaine version **ne partagent pas la même clé**. Android refusera de mettre à jour une installation existante (« conflit de package »). Il faudra désinstaller d'abord, ce qui efface la session et les données locales de l'application. Ce sera encore le cas à chaque nouvelle construction, tant qu'une clé de signature stable n'est pas configurée.

### 8.3 Effets automatiques d'une fusion dans `main`
1. **Publication Android immédiate** (`mobile-apk.yml`) : nouvel APK public, signé avec une nouvelle clé de debug.
2. **Déploiement Render probable** : `render.yaml` ne définit pas `autoDeploy`, or Render déploie par défaut à chaque commit sur la branche liée. Le réglage réel du tableau de bord n'est pas visible d'ici. Si le déploiement automatique est actif, la fusion exécute `start.sh`, donc `php artisan migrate --force` **sur la base de production**, sans préproduction.

**Fusionner revient donc à déployer et à publier.** Les vérifications de préproduction doivent précéder la fusion, ou bien il faut désactiver auparavant le déploiement automatique et le workflow de publication.
