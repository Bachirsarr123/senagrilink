# Lot 5 — Écrans par rôle : compte rendu

*Branche `claude/lot5-ecrans-par-role`, partie de `claude/lot4-gps-fiable`. Rien n'a été fusionné ni déployé, et aucun APK n'a été publié.*

## 1. Ce qui change

| Besoin | Maintenant | Site | Application |
|---|---|---|---|
| **Page « À faire » pour chaque rôle** | L'écran d'accueil de chaque rôle liste ce qui attend une action de sa part : commande à confirmer, préparer ou recevoir, mission à accepter, dépôt à examiner ou réceptionner, incident à décider, stock sous le seuil. Le plus urgent et le plus ancien viennent d'abord, et ce qui attend depuis plus de 24 h est signalé « en retard ». Un appui ouvre l'endroit où agir. | ✅ | ✅ |
| **Fiche d'une commande** | Une seule page pour tous les acteurs : état, étape attendue, **toutes les étapes datées avec leur auteur**, livraison et transporteur, incidents et décisions, lien vers le lot et vers la carte. Chacun y voit **ses** boutons. Le serveur retire ce qui ne regarde pas le lecteur : le transporteur ne voit pas le prix, et seul l'acheteur voit le code de réception. | ✅ | ✅ |
| **Toutes les actions du gestionnaire sur l'application** | Dépôts (accepter, refuser, affecter ou retirer le transporteur, **réception contrôlée**, refus de la marchandise), commandes (confirmer, refuser, préparer, prête, organiser la livraison, changer de transporteur, annuler) et incidents (décisions proposées par le serveur, motif exigé quand il le faut). | ✅ (déjà) | ✅ **nouveau** |
| **Application en onglets** | « À faire » d'abord, puis les écrans du rôle. Gestionnaire : Dépôts, Commandes, Incidents, Stocks. Producteur : Récoltes, Dépôts. Transporteur : Livraisons, Acheminements. Acheteur : Catalogue, Commandes. | — | ✅ |
| **Tableau des exceptions de l'administrateur** | En tête du tableau de bord, du plus grave au moins grave : incidents non décidés, **trajets sans position GPS depuis 15 min**, commandes et dépôts sans réponse depuis 48 h, missions non acceptées, livraisons arrivées mais non remises, litiges, stocks sous le seuil. | ✅ | — (le site, comme prévu en Q17) |
| **QR code du lot (Q13)** | Affiché sur la page de traçabilité du lot, avec une **étiquette imprimable**. À la réception, le gestionnaire **le scanne avec l'appareil photo** de l'application pour remplir le code du lot. | ✅ | ✅ |
| **Libellés et nom unifiés** | Nom de l'application Android : **SenAgriLink** (au lieu de « agri_platform_mobile »). Mêmes libellés de statuts et d'actions sur le site et l'application. | ✅ | ✅ |
| **Vrais messages d'erreur** | Les erreurs de saisie sont en français (« Le champ produit doit être un nombre entier. ») au lieu de l'anglais. Les messages propres à chaque formulaire sont inchangés. | ✅ | ✅ |

## 2. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **200 réussis**, 10 ignorés (tests réservés à PostgreSQL) |
| Tests automatiques du serveur, **PostgreSQL 16** | GitHub Actions | Voir §5 |
| Tests du site, compilation de production | Local | **105 réussis**, compilation sans avertissement |
| Tests de l'application, dont la mise en page des **5 nouveaux écrans** sur 5 formats et 2 tailles de texte | Local (Flutter 3.47.7) | **314 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur, 5 comptes distincts** (acheteur, gestionnaire, autre acheteur, transporteur, administrateur) | Chromium | **11 contrôles sur 11 réussis** |

**Le parcours navigateur contrôle :**
- l'arrivée de chaque rôle sur « À faire » ;
- la commande à confirmer et le stock en alerte chez le gestionnaire ;
- l'ouverture de la fiche, la confirmation depuis la fiche et les étapes mises à jour avec leur auteur ;
- la même fiche vue par l'acheteur, et refusée à un autre acheteur ;
- la mission à accepter dans « À faire » du transporteur ;
- le tableau des exceptions de l'administrateur ;
- le QR code du lot dessiné, avec son impression ;
- un message d'erreur en français.

Les tests de l'application ont révélé un débordement d'affichage dans la fenêtre de réception d'un dépôt. Il est corrigé.

**Non vérifié :**
- aucun test sur un téléphone Android réel : le **scan du QR code avec l'appareil photo** ne peut être essayé que sur un vrai téléphone ;
- aucun test en préproduction Render, ni sur la production.

## 3. Ce que je n'ai pas touché

- **Langue de la production** : le serveur parle désormais français par défaut, et la préproduction aussi. Mais `render.yaml` (production) garde encore `APP_LOCALE=en`, car je ne modifie aucun réglage de production sans votre accord. À la mise en production, il faudra passer `APP_LOCALE` à `fr`. Sans cela, les messages d'erreur génériques resteraient en anglais.
- **Aucune nouvelle migration** dans ce lot : la base de données est inchangée.

## 4. Limites connues

| Limite | Suite |
|---|---|
| La validation des comptes de gestionnaires et de transporteurs par l'administrateur (Q9) n'existe pas encore | Lot 6 |
| Notifications push sur le téléphone (Firebase, Q15) | Lot 6 |
| Les rapports détaillés et la gestion des comptes restent sur le site (choix Q17) | — |
| Pas de fiche « lot » distincte : la page de traçabilité en tient lieu (parcours complet et QR code) | Suffisant pour l'instant |
| Une nouvelle dépendance, `mobile_scanner` (lecture des QR codes), ajoute l'accès à l'appareil photo et quelques mégaoctets à l'APK | Normal pour cette fonction |

## 5. Vérifications GitHub sur la branche

À compléter après l'exécution des vérifications sur la branche poussée.
