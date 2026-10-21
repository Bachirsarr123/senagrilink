# Lot 6 — Notifications et comptes : compte rendu

*Branche `claude/lot6-notifications-comptes`, partie de `claude/lot5-ecrans-par-role`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Ce qui change

| Besoin | Maintenant | Site | Application |
|---|---|---|---|
| **Validation des comptes (Q9)** | Un **gestionnaire d'entrepôt** ou un **transporteur** qui s'inscrit reçoit « Compte créé : il sera activé après validation par l'administrateur ». Aucune session n'est ouverte. Les administrateurs sont prévenus, et le compte apparaît dans le **tableau des exceptions**. L'administrateur **valide** le compte, ou le **refuse avec un motif**. Tant que le compte n'est pas actif, la connexion et toute action sont refusées, avec un message clair (le motif, en cas de refus). Validé, l'utilisateur reçoit « Votre compte SenAgriLink est activé ». Les producteurs et les acheteurs restent actifs dès l'inscription. | ✅ | ✅ (inscription) |
| **Journal d'audit complet** | En plus des étapes des circuits (déjà historisées), le journal enregistre l'inscription, les connexions réussies, échouées (mauvais mot de passe) et refusées (compte non actif), la déconnexion, les **modifications de profil** (quels champs, jamais le mot de passe), ainsi que la validation, le refus, le blocage et le déblocage d'un compte. Chaque entrée porte **son auteur et son adresse IP**. Page **Journal** pour l'administrateur, filtrable par type, action et période, 50 entrées par page. | ✅ | — (le site, choix Q17) |
| **Liste des notifications sur l'appli** | Écran **Notifications** ouvert depuis « À faire » : les plus récentes d'abord, les non lues en évidence, un appui marque comme lu, et « Tout lire ». Sur le site, « Tout marquer comme lu » est ajouté au panneau des notifications. | ✅ | ✅ |
| **Notifications push (Q15)** | **Chaque notification part aussi sur le téléphone** de son destinataire, avec le même texte et un titre court (« Nouvelle mission », « Commande chargée »…). Le téléphone s'enregistre après la connexion et **se retire à la déconnexion**. Un téléphone qui passe d'un compte à l'autre n'avertit jamais deux personnes. Un jeton périmé (application désinstallée) est oublié. Un envoi qui échoue n'interrompt jamais l'opération en cours. | — | ✅ (après la configuration Firebase, §3) |

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **205 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16** | Local | **215 réussis** |
| Cycle des migrations : retour ciblé de 000023, puis 000022 à 000018, réapplication, retour complet | Local (PostgreSQL), même script que la CI | Réussi |
| Envoi Firebase : jeton d'accès signé, message envoyé au bon téléphone, jeton périmé oublié, aucun envoi sans configuration | Test automatique (Firebase simulé) | Réussi |
| Tests du site, compilation de production | Local | **108 réussis**, compilation sans avertissement |
| Tests de l'application, dont l'écran Notifications sur 5 formats et 2 tailles de texte | Local (Flutter 3.47.7) | **328 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur** : inscription d'un transporteur, connexion refusée, exception, validation, connexion, journal | Chromium | **8 contrôles sur 8 réussis** |
| Vérifications automatiques GitHub | GitHub Actions | Voir §6 |

**Non vérifié :**
- **aucune notification réellement reçue sur un téléphone** : il faut d'abord le projet Firebase (§3), puis un essai sur un vrai téléphone ;
- aucun test en préproduction Render, ni sur la production.

## 3. Ce qu'il vous reste à faire pour les notifications push

Je ne crée aucun compte ni aucune clé : ces étapes sont à faire par vous (gratuit, environ 15 minutes). Je vous guiderai pas à pas le moment venu.

1. Sur **console.firebase.google.com**, créez un projet `SenAgriLink`. Google Analytics n'est pas nécessaire.
2. Ajoutez une **application Android** avec l'identifiant actuel de l'application, celui que vous avez demandé de garder. Je vous le donnerai.
3. Dans **Paramètres du projet → Général**, relevez quatre valeurs : la clé d'API Web, l'ID de l'application, l'ID de l'expéditeur et l'ID du projet. Elles ne sont pas secrètes.
   - Elles vont dans **GitHub → Settings → Secrets and variables → Actions → Variables**, sous les noms `FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID` et `FIREBASE_PROJECT_ID`.
   - L'APK de test les reçoit automatiquement.
4. Dans **Paramètres du projet → Comptes de service**, générez une **clé privée**, un fichier JSON.
   - **Celle-ci est secrète** : ne me l'envoyez pas.
   - Encodez-la en base64 et collez-la vous-même dans Render, dans la variable `FIREBASE_CREDENTIALS_BASE64` du serveur (préproduction d'abord).

Sans ces réglages, tout fonctionne comme aujourd'hui : les notifications restent visibles dans l'application et sur le site.

## 4. Base de données

- **Nouvelle migration** : `2025_01_01_000023_create_comptes_et_notifications`.
  - Le statut du compte passe en texte (actif, bloqué, en attente de validation, refusé).
  - Elle ajoute qui a validé ou refusé le compte, quand, et le motif d'un refus.
  - Elle crée la table `appareils` (téléphones à notifier) et ajoute l'adresse IP au journal.
- **Reprise** : les comptes existants gardent leur statut. Les comptes de test déjà créés ne sont pas concernés par la validation.
- **Retour arrière**, toujours de la plus récente à la plus ancienne : `migrate:rollback --path=database/migrations/2025_01_01_000023_create_comptes_et_notifications.php --force`, puis 000022 à 000018. Un compte encore en attente ou refusé redevient alors « bloqué ». Ce cycle est vérifié à chaque exécution de `backend-ci.yml`.

## 5. Limites connues

| Limite | Suite |
|---|---|
| Pas de notifications push sur le site ni sur iPhone (Q16) | Hors périmètre |
| Aucun e-mail n'est envoyé (validation de compte, mot de passe oublié) : aucun service d'e-mail n'est configuré | À décider |
| Un administrateur créé par un autre administrateur n'a pas besoin de validation (comportement voulu) | — |
| Les positions GPS ne figurent pas dans le journal (elles ont leur propre historique, purgé à 30 jours) | Voulu (Q11) |

## 6. Vérifications GitHub sur la branche

| Vérification | Commit | Résultat |
|---|---|---|
| Serveur : SQLite, PostgreSQL 16 (concurrence comprise), cycle des migrations 000023 → 000018, garde de démarrage (exécution 39666537115) | `d892d09` | **Réussi** |
| Site : tests et compilation de production (exécution 39666537002) | `d892d09` | **Réussi** |
| Mobile : analyse, tests, **APK de test construit avec les paquets Firebase** et contrôlé, sans publication (exécution 39666537214) | `d892d09` | **Réussi** |
