# Lot 9 — Guidage dans l'application et moins d'appuis : compte rendu

*Branche `claude/lot9-guidage-simplicite`, partie de `claude/lot8-positions-automatiques`. Rien n'a été fusionné, et aucun APK n'a été publié. Le déploiement de la préproduction se fait seulement avec votre accord.*

## 1. Ce qui change

### Guidage, comme Yango (site et application)

| Pour qui | Maintenant |
|---|---|
| **Transporteur** (et producteur qui livre lui-même) | Le bouton **« 🧭 Conduire »** remplace Google Maps. Il ouvre l'écran **Conduite** : la carte suit le véhicule et le chemin est tracé **par la route**. En haut, la consigne en gros : « ➡ Dans 350 m — Tournez à droite sur Route de Dakar ». En bas, la destination, « Arrivée vers 14:35 · 12 min · 7,1 km » et **un seul gros bouton** qui change selon l'étape : « Je suis chez le producteur », puis « Marchandise chargée », puis « Je pars », puis « Je suis arrivé ». |
| **Voix** | Les consignes sont **lues à voix haute en français**. Un bouton 🔊 / 🔇 coupe la voix, et ce choix est retenu. |
| **Arrivée** | À moins de 150 m du but, l'écran affiche et dit « Vous êtes arrivé ? » : le gros bouton devient vert foncé. |
| **Erreur de route** | Le trajet est **recalculé tout seul** dès que le véhicule s'écarte du chemin de plus de 80 m. |
| **Autres acteurs** | Pendant le trajet, leur carte de suivi montre le véhicule et le **chemin restant par la route**, avec l'heure d'arrivée (lot 4). |

La position envoyée pour le guidage sert **seulement au calcul** : elle n'est ni gardée ni montrée. Le partage avec les autres acteurs reste limité au trajet (règle Q11).

### Moins d'appuis

| Rôle | Avant | Maintenant |
|---|---|---|
| **Producteur** | Environ 8 appuis pour déposer | Bouton « 🌾 Déposer ma récolte chez [entrepôt le plus proche] ». La dernière récolte, sa quantité, le transporteur, la date et la position sont **préremplis** : il vérifie, puis envoie. |
| **Gestionnaire** | Ouvrir chaque écran pour agir | Dans **« À faire »**, chaque ligne porte **son bouton** : « Accepter », « Commencer la préparation », « Commande prête ». Pour confier une mission, le bouton propose d'office **« Confier à Alioune Fall (à 1,5 km) »** : le transporteur **libre** le plus **proche**. |
| **Transporteur** | Accepter, puis chercher la mission | « J'accepte la mission » dans « À faire » ouvre **directement** l'écran Conduite. |
| **Acheteur** | Quantité tapée au clavier | Boutons **− / +**, par 10 kg (par 1 kg pour un petit stock) |
| **Tous** | Fenêtre « Confirmer ? » à chaque étape | Pour ces actions d'un appui : **plus de fenêtre**, mais **quelques secondes pour annuler** (« « Marchandise chargée » dans 3 s · Annuler »). Les refus et les annulations gardent leur confirmation et leur motif. |

**Une seule définition** pour les deux interfaces :
- les actions d'un appui : `App\Support\AFaire::ACTIONS_DIRECTES` ;
- le transporteur proposé : `App\Support\PropositionTransporteur` ;
- le calcul d'itinéraire : `App\Services\Itineraire` ;
- le prochain lieu à rejoindre : `NavigationController::versOu`.

## 2. Ce qu'il vous reste à faire : la clé d'itinéraire

Sans clé, tout fonctionne, mais le guidage se limite à une **ligne droite** avec une direction (« Dirigez-vous vers le sud-est, 5,4 km à vol d'oiseau »).

Pour le vrai chemin par la route, avec les consignes rue par rue :
1. Créez la clé gratuite **OpenRouteService**, comme indiqué dans la conversation.
2. Dans Render, service **senagrilink-backend-preprod**, ouvrez **Environment** et ajoutez la variable **`ROUTAGE_CLE`** avec la clé. Vous la collez vous-même : elle est secrète, ne me l'envoyez pas.

L'offre gratuite d'OpenRouteService autorise 2 000 trajets par jour. Le serveur garde chaque calcul 2 minutes, pour une position proche (environ 50 m) et le même but.

## 3. Vérifications réalisées

| Type | Où | Résultat |
|---|---|---|
| Tests automatiques du serveur, SQLite | Local | **215 réussis**, 10 ignorés (réservés à PostgreSQL), dont **5 nouveaux** : guidage OpenRouteService simulé, repli sans service, consignes OSRM en français, guidage d'un dépôt, transporteur proposé et actions directes |
| Tests du site, compilation de production | Local | **114 réussis**, compilation sans avertissement |
| Tests de l'application, dont l'écran Conduite sur 5 formats et 2 tailles de texte | Local (Flutter 3.47.7) | **347 réussis** ; analyse sans erreur |
| **Parcours réel dans un navigateur**, position GPS simulée, 4 comptes | Chromium | **8 contrôles sur 8 réussis** (détail ci-dessous) |
| Vérifications automatiques GitHub | GitHub Actions | Voir §6 |

**Le parcours navigateur contrôle :**
- **acheteur :** quantité choisie avec « + » ; livraison à sa position actuelle ;
- **gestionnaire :** accepter, préparer, commande prête, puis « Confier à Alioune Fall », chacun d'un seul appui depuis « À faire » ;
- **transporteur :**
  - « J'accepte la mission » ouvre la page Conduite, avec la consigne, l'entrepôt et l'heure d'arrivée ;
  - arrivé sur place, « Vous êtes arrivé ? » s'affiche ;
  - le gros bouton fait l'étape et propose la suivante ;
- **producteur :** dépôt prérempli d'un appui.

**Non vérifié :**
- **aucun essai sur un vrai téléphone**, en particulier la voix (elle dépend de la synthèse vocale du téléphone : sur Android, « Synthèse vocale Google » en français) ;
- le vrai chemin routier : il demande la clé OpenRouteService (§2). Il est testé avec un service simulé.

## 4. Base de données

**Aucune nouvelle migration** dans ce lot.

## 5. Limites connues

| Limite | Suite |
|---|---|
| Sans réseau, la consigne affichée reste celle du dernier calcul, mais la position continue d'avancer sur la carte | Suffisant pour démarrer |
| Le transporteur proposé est classé selon sa dernière position connue pendant un trajet (7 derniers jours) ; sinon, le moins occupé | À affiner si besoin |
| Sur le site, la voix dépend du navigateur (Chrome et Edge la proposent en français) | — |
| Une nouvelle dépendance mobile, `flutter_tts` (lecture des consignes à voix haute) | Normal pour cette fonction |

## 6. Vérifications GitHub sur la branche

*À compléter après l'exécution.*
