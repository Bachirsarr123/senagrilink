# SenAgriLink — Cahier des charges fonctionnel v2, état réel et plan de refonte

*Établi le 21 octobre 2026 à partir du cahier des charges fonctionnel rédigé par le porteur du projet. Le code réel a été relu ligne par ligne (backend Laravel, site Angular, application Flutter). Aucun code n'a été modifié.*

**Comment lire ce document :**
- §1 : la réponse courte (corriger ou refaire ?).
- §2 et §3 : ce que vous avez décidé, puis ce que je propose d'ajouter à votre cahier des charges.
- §4 et §5 : les deux circuits, décrits étape par étape.
- §6 : les questions à trancher. Une recommandation est donnée à chaque fois : il suffit de répondre « oui » ou de corriger.
- §7 : l'état réel de chaque fonctionnalité.
- §8 : le plan de réalisation.

---

## 1. Réponse courte

**Il faut refaire la logique des processus et la plupart des écrans, mais pas tout.**

| On garde (fiable, testé) | On refait |
|---|---|
| Connexion et sessions, sécurité des comptes, rôles et contrôles d'accès | Les étapes de chaque circuit : aujourd'hui, elles s'arrêtent en route ou mènent à des impasses |
| Base de données (on l'enrichit, on ne la jette pas) | La gestion du stock : aujourd'hui, il n'y a ni stock « réservé » ni historique des mouvements |
| Protection contre les doubles clics et les opérations simultanées | Les écrans de chaque rôle, organisés autour de « ce que j'ai à faire » |
| Traçabilité des lots, carte de suivi | Le GPS côté transporteur : aujourd'hui, il s'arrête dès qu'on quitte l'écran |
| Tests automatiques existants | L'application mobile, pour qu'elle offre les mêmes fonctions que le site |

**Pourquoi les incohérences que vous voyez existent.** Le site et l'application ont été construits par petites corrections successives, sans que les étapes de chaque circuit soient écrites une fois pour toutes. Chaque écran a fait ses propres choix. Votre cahier des charges règle ce problème : il décrit enfin le métier complet. Ce document le transforme en règles précises, que le code suivra.

---

## 2. Décisions déjà prises

| Sujet | Décision |
|---|---|
| Prix | **Fixé par le producteur** |
| Site et application | **Chaque rôle peut utiliser le site comme l'application** (pour l'instant) |
| Identifiant Android | **Conservé** : `com.agriplatform.agri_platform_mobile` |
| Base de données de production | Hébergée chez **Neon** |
| Suivi GPS | **Pour les deux circuits** (producteur vers entrepôt, entrepôt vers acheteur) |
| Réception producteur vers entrepôt | Comptée **uniquement après validation par le gestionnaire** |
| Réception entrepôt vers acheteur | **Confirmée par l'acheteur**, avec possibilité de déclarer une anomalie |
| Données actuelles (Q1) | **Comptes de test uniquement** : aucune donnée réelle à préserver (le 21 octobre 2026) |
| Questions Q2 à Q17 | **Toutes les recommandations du §6 sont acceptées** (le 21 octobre 2026) |

---

## 3. Améliorations proposées à votre cahier des charges

Votre cahier dit **quoi** faire. Pour que le résultat soit cohérent, il faut aussi préciser **comment** dans les cas suivants. Chaque point est une proposition à valider (voir §6).

1. **Trois quantités pour chaque stock** au lieu d'une seule :
   - le **stock physique** : ce qui est réellement dans l'entrepôt ;
   - le **stock réservé** : ce qui est promis à des commandes confirmées, pas encore parties ;
   - le **stock disponible**, égal au physique moins le réservé : c'est **le seul chiffre affiché à l'acheteur**.

   C'est ce qui rend vrai votre point 8 (« les quantités disponibles tiennent compte des commandes déjà réservées »).
2. **Un journal des mouvements de stock.** Chaque entrée, réservation, sortie, retour ou correction est une ligne, avec la quantité, l'auteur, la date et l'opération d'origine (réception n°…, commande n°…). Le stock n'est jamais modifié « à la main » sans laisser de trace. C'est ce qui garantit qu'une quantité n'est jamais déduite deux fois.
3. **Le prix au kilo, porté par le lot.**
   - Le producteur indique un prix au kilo quand il enregistre sa récolte, et peut le modifier tant que le lot n'est pas vendu.
   - Le prix est **figé sur la commande** au moment où l'acheteur commande : un changement de prix ultérieur ne modifie pas une commande passée.
4. **Un panier** : l'acheteur peut commander plusieurs produits en une fois. Si les produits sont dans des entrepôts différents, le système crée automatiquement une commande par entrepôt (donc une livraison chacune).
5. **Une seule notion d'« incident »** pour tout ce qui sort du parcours normal (panne, retard, marchandise abîmée, acheteur absent…). Chaque incident a :
   - un type, un déclarant, une description, une photo facultative ;
   - un **responsable de la prochaine action** et un statut (ouvert ou résolu) ;
   - une décision finale (reprendre, changer de transporteur, retour à l'entrepôt avec remise en stock, annuler).

   Les incidents ouverts remontent automatiquement dans le tableau de bord du gestionnaire et dans celui de l'administrateur.
6. **Une preuve de livraison simple.** À l'arrivée, l'acheteur confirme dans l'application. S'il n'a pas son téléphone, il donne au transporteur un **code à 4 chiffres** affiché dans sa commande. Le transporteur peut ajouter une photo.
7. **Le mode de transport choisi dès la demande** au circuit A : « un transporteur vient chercher ma récolte » ou « je livre moi-même ». Aujourd'hui, le producteur qui livre lui-même bloque le circuit (voir §7).
8. **Le lieu de chaque acteur, enregistré une fois sur une carte.** Le producteur, l'entrepôt et l'acheteur placent leur adresse sur une carte dans leur profil. Toutes les missions sont ensuite **préremplies** automatiquement. C'est indispensable pour :
   - le trajet ;
   - l'heure d'arrivée estimée ;
   - le bouton « Itinéraire » du transporteur.
9. **Validation des comptes sensibles.**
   - Un nouveau gestionnaire d'entrepôt ou transporteur reste **« en attente de validation »** jusqu'à ce que l'administrateur l'active.
   - Les producteurs et acheteurs sont actifs tout de suite.
10. **Notifications sur le téléphone (push).**
    - Aujourd'hui, les notifications n'arrivent que si l'application est ouverte.
    - Proposition : notifications push Android (service gratuit Firebase) pour les événements importants, et une **liste de notifications** sur mobile comme sur le site.
11. **Une page « À faire » pour chaque rôle**, pas seulement pour le gestionnaire. C'est la page d'accueil après la connexion, et chaque ligne a son bouton d'action direct.
12. **Une seule page par commande et par lot** (« fiche »). Elle regroupe tout : état, étapes passées avec date et auteur, transporteur, carte si le transport est en cours, incidents. Tous les rôles concernés voient la même fiche, chacun avec ses propres boutons.

---

## 4. Circuit A — De la récolte à l'entrepôt

### 4.1 Les étapes

| # | Étape (nom affiché) | Qui agit | Ce que le système fait automatiquement |
|---|---|---|---|
| 1 | **Récolte enregistrée** | Producteur : produit, quantité, date, prix au kilo | Crée le lot et son identifiant `LOT-AAAA-XXXXXX` (et son QR code, voir Q13) |
| 2 | **Dépôt demandé** | Producteur : choisit un entrepôt **qui a la place**, et le mode de transport | Reprend le produit et la quantité du lot, sans ressaisie. Notifie le gestionnaire. |
| 3a | **Dépôt accepté** | Gestionnaire | Réserve la place dans l'entrepôt. Notifie le producteur. |
| 3b | *Dépôt refusé* (motif obligatoire) | Gestionnaire | Libère tout. Notifie le producteur. Le lot redevient disponible pour une autre demande. |
| 4 | **Transporteur affecté** *(si le mode est « transporteur »)* | Gestionnaire : choisit dans la liste des transporteurs **disponibles** | Crée la mission (adresse du producteur, téléphone, lot, quantité). Notifie le transporteur. |
| 5 | **Mission acceptée** *(ou refusée, alors retour à l'étape 4)* | Transporteur | Notifie le gestionnaire |
| 6 | **Arrivé chez le producteur**, puis **Chargé** | Transporteur | — |
| 7 | **En route** | Transporteur (ou producteur, s'il livre lui-même) | **Démarre le GPS** sur le téléphone de la personne qui conduit. Le producteur et le gestionnaire voient la carte et l'heure d'arrivée estimée. |
| 8 | **Arrivé à l'entrepôt** | Transporteur ou producteur | Arrête le GPS. Notifie le gestionnaire (« Réception à contrôler »). |
| 9a | **Réceptionné** | Gestionnaire : contrôle l'identifiant du lot (saisie ou QR), la quantité réelle et l'état | Crée l'entrée de stock liée au lot, avec un mouvement « entrée ». Met à jour la traçabilité. Notifie le producteur. **Le lot ne peut plus être modifié.** |
| 9b | *Réceptionné partiellement* | Gestionnaire : quantité reçue inférieure, motif obligatoire | Comme 9a, avec la quantité réelle. L'écart est visible dans l'historique et notifié au producteur. |
| 9c | *Réception refusée* (motif obligatoire) | Gestionnaire | Aucun stock créé. Ouvre un incident. La marchandise repart. |

**À tout moment avant l'étape 7 :**
- le producteur peut annuler, et le transporteur est alors prévenu ;
- le transporteur peut déclarer un incident (panne, retard, accès impossible).

**Après l'étape 7,** le seul moyen d'annuler est un incident, avec une décision du gestionnaire.

### 4.2 Les règles
- Une étape ne peut être franchie que dans cet ordre, et seulement par la personne indiquée. Tout autre cas est refusé avec un message clair.
- Chaque étape enregistre automatiquement **qui, quand, et ce qui a changé**. Cet historique est visible sur la fiche du lot.
- Une étape franchie n'est jamais modifiée en silence. Une erreur se corrige par une nouvelle opération (par exemple une correction de stock), avec un motif.

---

## 5. Circuit B — De l'entrepôt à l'acheteur

### 5.1 La commande

| # | Étape | Qui agit | Ce que le système fait automatiquement |
|---|---|---|---|
| 1 | **Commande passée** | Acheteur : panier, quantités, adresse de livraison préremplie | Vérifie la quantité **disponible** (physique moins réservé). Fige le prix. Notifie le gestionnaire et le producteur du lot. |
| 2a | **Commande confirmée** | Gestionnaire | **Réserve** la quantité (mouvement « réservation »). Notifie l'acheteur. |
| 2b | *Commande refusée* (motif) | Gestionnaire | Notifie l'acheteur |
| 3 | **En préparation**, puis **Prête** | Gestionnaire : vérifie les quantités et les lots préparés | Crée la mission de livraison, avec l'adresse de l'entrepôt, celle de l'acheteur, le contact, les lots et les quantités |
| 4 | **Transporteur affecté**, puis **Mission acceptée** | Gestionnaire, puis transporteur | Comme au circuit A. Un refus renvoie la mission dans « Transporteur à affecter ». |
| 5 | **Chargé** | Transporteur, et le gestionnaire confirme la sortie | **Sortie de stock** : la quantité passe de « réservé » à « sorti » (mouvement « sortie »). Elle n'est déduite qu'**une seule fois**. |
| 6 | **En route** | Transporteur | Démarre le GPS. L'acheteur voit la carte, le trajet, l'heure d'arrivée estimée et l'heure du dernier point. |
| 7 | **Arrivé chez l'acheteur** | Transporteur | Arrête le GPS. Notifie l'acheteur. |
| 8a | **Réception confirmée** | Acheteur dans l'application, ou code à 4 chiffres saisi par le transporteur | La commande est **terminée**. Notifie tout le monde. Retirée des tâches actives et ajoutée à l'historique. |
| 8b | *Réception avec anomalie* | Acheteur : quantité manquante, produit abîmé ou erreur, avec photo facultative | Ouvre un **litige** traité par le gestionnaire, puis par l'administrateur si besoin |

**Annulation :**
- L'acheteur peut annuler lui-même **jusqu'à l'étape 2** (avant la confirmation).
- De l'étape 2 à l'étape 4, il demande l'annulation au gestionnaire, qui l'accepte ou la refuse. Une annulation acceptée **libère la réservation**.
- Après le chargement, l'annulation passe par un incident (retour à l'entrepôt, puis remise en stock).

### 5.2 Les incidents (pour les deux circuits)

| Situation | Qui la déclare | Qui décide ensuite | Issues possibles |
|---|---|---|---|
| Transporteur indisponible, ou mission refusée | Transporteur | Gestionnaire | Affecter un autre transporteur |
| Mission sans transporteur depuis plus de X heures | Système (automatique) | Gestionnaire, puis administrateur | Affecter |
| Retard | Transporteur, ou système si l'heure d'arrivée estimée est dépassée | — (information) | L'heure estimée est mise à jour, les acteurs sont notifiés |
| Panne, accès impossible | Transporteur | Gestionnaire | Reprendre plus tard, ou changer de transporteur |
| GPS ou réseau perdu | Système | — | Affiche « Dernière position connue il y a X min ». Jamais affiché comme une position actuelle. |
| Acheteur absent, livraison impossible | Transporteur | Gestionnaire | Nouvelle tentative, ou retour à l'entrepôt avec remise en stock |
| Marchandise abîmée ou quantité incorrecte | Gestionnaire (réception, circuit A) ou acheteur (circuit B) | Gestionnaire, puis administrateur | Réception partielle, avoir, retour |
| Écart d'inventaire | Gestionnaire | Gestionnaire, avec motif | Correction de stock, enregistrée comme mouvement |

---

## 6. Questions à trancher

Pour chaque question : **ma recommandation en gras**. Répondez « d'accord » ou corrigez.

| # | Question | Recommandation |
|---|---|---|
| Q1 | **Y a-t-il de vraies données en production** (vrais producteurs, vraies commandes) ou seulement des comptes de test ? | **À me dire.** S'il n'y a que des tests, on peut repartir d'une base propre : c'est plus simple, plus rapide et sans risque. |
| Q2 | Le prix est-il au **kilo** ? Négociable ? | **Au kilo, fixé par le producteur, non négociable pour cette version.** L'acheteur voit le prix et le total. |
| Q3 | Le stock est-il réservé dès la **commande passée**, ou seulement à la **confirmation** ? | **À la confirmation**, comme dans votre cahier. L'acheteur ne peut toutefois pas commander plus que le disponible. |
| Q4 | Le panier peut-il mélanger plusieurs entrepôts ? | **Oui : une commande, donc une livraison, par entrepôt, créée automatiquement.** |
| Q5 | Le transporteur peut-il **refuser** une mission ? | **Oui**, avec un motif. La mission revient au gestionnaire. |
| Q6 | Qui confirme la livraison si l'acheteur n'a pas l'application ? | **Le code à 4 chiffres saisi par le transporteur.** Sans confirmation ni anomalie sous 48 h, clôture automatique, signalée à l'administrateur. |
| Q7 | Le producteur peut-il **livrer lui-même** à l'entrepôt ? | **Oui**, c'est un mode de transport au choix. Le GPS fonctionne alors depuis son téléphone. |
| Q8 | Un lot peut-il être réparti **sur plusieurs entrepôts** ? | **Oui, par plusieurs demandes de dépôt partielles** sur le même lot. |
| Q9 | Faut-il **valider** les gestionnaires et les transporteurs avant activation ? | **Oui**, par l'administrateur. |
| Q10 | Le GPS doit-il continuer **téléphone verrouillé** ? | **Oui sur Android**, avec une notification permanente « Suivi de livraison actif » pendant la mission (obligatoire sous Android). Sur iPhone, voir Q16. |
| Q11 | Qui peut voir la **position** du véhicule ? | **Uniquement les acteurs de la mission en cours**, et seulement pendant le trajet : producteur ou acheteur concerné, gestionnaire de l'entrepôt, administrateur. Les positions sont effacées après 30 jours. |
| Q12 | Qui peut consulter la **traçabilité** d'un lot ? | **Les acteurs du lot et l'administrateur.** Une page publique par QR code pourra venir plus tard. |
| Q13 | Faut-il un **QR code** par lot dès cette version ? | **Oui, en version simple.** Le QR s'affiche et s'imprime depuis la fiche du lot. Le gestionnaire le scanne avec l'application à la réception. |
| Q14 | Quelles **sorties de stock manuelles** sont permises ? | **Perte, avarie, retrait par le producteur, correction d'inventaire**, toujours avec un motif. Jamais pour une commande : la sortie d'une commande se fait au chargement. |
| Q15 | Notifications push sur téléphone ? | **Oui**, avec Firebase (gratuit) pour Android. |
| Q16 | **iPhone** : faut-il une application iPhone ? | **Pas pour cette version.** Elle demande un Mac, un compte Apple payant (99 $/an) et une validation par Apple. Les utilisateurs d'iPhone se servent du site, installable sur l'écran d'accueil (déjà en place), mais le GPS en arrière-plan n'y fonctionne pas. |
| Q17 | Le site et l'application doivent-ils offrir **exactement les mêmes fonctions** à tous les rôles ? | **Oui pour producteur, acheteur et transporteur. Pour le gestionnaire et l'administrateur : toutes les actions sur les deux, mais les rapports détaillés et la gestion des comptes seulement sur le site.** Cela réduit le travail sans gêner l'usage. |

---

## 7. État réel de chaque fonctionnalité

**Légende :**
- ✅ fait et vérifié dans le code ;
- 🟡 à moitié fait ;
- ⚠️ présent mais ne fonctionne pas réellement ;
- ❌ absent.

« Site » et « Appli » indiquent sur quel support la fonction existe.

*Méthode : lecture complète du code (chaque constat renvoie à un fichier et à une ligne dans les rapports d'analyse). Aucun test sur un téléphone réel.*

### 7.1 Circuit A — Récolte vers entrepôt

| Fonction | État | Détail |
|---|---|---|
| Enregistrer une récolte | ✅ Site et appli | L'annulation n'existe que sur le site |
| Identifiant de lot automatique | ✅ | Format `LOT-2026-XXXXXX` |
| Voir les entrepôts et leur capacité, demander un dépôt | 🟡 Site seulement | **Absent de l'appli.** Elle renvoie le producteur vers le site. Le produit et la quantité sont à ressaisir. |
| Le gestionnaire est prévenu d'une nouvelle demande | ❌ | Aucune notification : il doit ouvrir la page pour la voir |
| Le producteur livre lui-même | ⚠️ | **Impasse :** sans transporteur, la réservation ne peut jamais être réceptionnée |
| Mission envoyée au transporteur | 🟡 | Ni adresse ni coordonnées du producteur. Pas de téléphone sur l'appli. |
| GPS pendant l'acheminement | ❌ | Le GPS n'existe que pour les livraisons aux acheteurs |
| Carte pour le producteur et le gestionnaire | ❌ | Le gestionnaire n'a accès à aucune carte, quel que soit le circuit |
| Incident ou retard | ❌ | — |
| Contrôle à la réception (lot, quantité, état) | 🟡 Site seulement | Le **code du lot n'est pas affiché**. Pas de réception partielle ni de refus. |
| Entrée en stock automatique | ✅ Site seulement | — |
| Traçabilité de l'acheminement | ❌ | La traçabilité ignore le transport vers l'entrepôt |
| Lot verrouillé après réception | ❌ | **Une récolte reste modifiable après être entrée en stock et avoir été vendue** |

### 7.2 Circuit B — Entrepôt vers acheteur

| Fonction | État | Détail |
|---|---|---|
| Catalogue | 🟡 Site et appli | Aucune recherche ni filtre. La quantité affichée ignore les commandes en attente. |
| Panier (plusieurs produits) | ❌ | Un seul produit par commande |
| Prix | ⚠️ | **Aujourd'hui, c'est l'acheteur qui propose le prix**, contrairement à votre décision. Le producteur n'en fixe aucun. |
| Disponibilité réelle (stock réservé) | ❌ | Plusieurs commandes peuvent dépasser le stock. L'acheteur ne le découvre qu'au refus. |
| Confirmation de commande | 🟡 Site seulement | Le stock est déduit dès la confirmation. **Rien sur l'appli pour le gestionnaire.** |
| Préparation | ❌ | — |
| Affecter un transporteur disponible | 🟡 Site seulement | Aucune notion de disponibilité |
| Le transporteur accepte ou refuse | ❌ | La mission est imposée |
| Étapes de la mission | 🟡 | Il n'y en a que trois : en attente, en cours, livrée |
| Heure de départ réelle | ❌ | La date affichée « Parti le » est la date prévue saisie par le gestionnaire |
| L'acheteur confirme la réception | ❌ | C'est le transporteur qui déclare « livrée » |
| L'acheteur déclare une anomalie | ❌ | — |
| Commande terminée | ⚠️ | **Le statut « livrée » d'une commande n'est jamais atteint.** Elle reste « confirmée » pour toujours. |
| Signaler un problème | ⚠️ | Un seul bouton, sans motif. **Personne n'est prévenu, et la livraison reste bloquée définitivement** : aucune étape suivante n'existe, la commande ne peut plus être annulée. |
| Annuler après confirmation | 🟡 | Impossible dès qu'une livraison existe |
| Fiche commande complète | ❌ | Une simple ligne de tableau |

### 7.3 Suivi GPS

| Fonction | État | Détail |
|---|---|---|
| Carte avec la position du véhicule | ✅ Site et appli | Pour l'acheteur et le producteur, sur les livraisons aux acheteurs seulement |
| Heure de la dernière position, « dernière position connue » si elle est ancienne | ✅ | Seuil de 60 secondes |
| Envoi de la position par le transporteur | ⚠️ Appli seulement | **S'arrête dès qu'il quitte l'écran de la mission ou verrouille son téléphone** |
| Fonctionnement en arrière-plan (Android) | ❌ | Les permissions Android nécessaires sont absentes |
| Fonctionnement en arrière-plan (iPhone) | ❌ | — |
| Destination sur la carte | ⚠️ | La destination est un texte libre : la carte n'a pas de point d'arrivée |
| Heure d'arrivée estimée | ❌ | Seulement une distance à vol d'oiseau, souvent incalculable |
| Trajet | 🟡 | Lignes droites seulement |
| Bouton « Itinéraire » (Google Maps) | ❌ | — |
| Reprise après une coupure réseau | 🟡 | L'affichage reprend, mais les positions prises pendant la coupure sont perdues |
| Confidentialité | ⚠️ | **Les positions sont liées au transporteur, pas à la mission.** Un acheteur peut voir la position d'une mission précédente, ou d'une autre mission en cours. |
| Partage de position depuis le site (transporteur) | ❌ | — |
| Fond de carte | ⚠️ | Serveurs publics d'OpenStreetMap, non autorisés pour une application en production |
| Conservation des positions | ⚠️ | Aucune suppression automatique : la base grossit sans limite |

### 7.4 Notifications et temps réel

| Fonction | État | Détail |
|---|---|---|
| Notifications dans l'application | 🟡 | Il en manque beaucoup : nouvelle demande de dépôt, problème, retard. Pas de liste sur l'appli. |
| Mise à jour sans recharger la page | ⚠️ | Le système temps réel est prévu dans le code **mais n'est pas installé en production**. Seules des actualisations périodiques fonctionnent. |
| Notifications push (téléphone fermé) | ❌ | — |
| E-mail ou SMS | ❌ | — |

### 7.5 Écrans par rôle

| Rôle | Site | Appli |
|---|---|---|
| Producteur | 🟡 Tout est là, mais deux pages en double (« Planifier ventes » et « Demandes ») et des ressaisies | 🟡 **Pas de demande de dépôt** (l'étape clé), pas d'annulation |
| Gestionnaire | 🟡 Tout est là, mais réparti sur plusieurs pages. L'accueil est le tableau des stocks, pas les tâches. Saisie d'identifiants numériques. | ⚠️ **Lecture seule** : il ne peut rien faire |
| Acheteur | 🟡 Pas de fiche commande | 🟡 Pas de fiche commande |
| Transporteur | 🟡 Ne peut pas envoyer sa position | 🟡 Les acheminements sont cachés derrière une icône, sans demande de confirmation |
| Administrateur | 🟡 Compteurs et utilisateurs seulement. Pas de vue des exceptions, pas de lien vers les détails. | ❌ « Ce rôle n'a pas d'interface mobile » |
| Tableau « À faire » | ❌ | ❌ |
| Validation des comptes | ❌ (inscription libre et active tout de suite, y compris pour les transporteurs et gestionnaires) | ❌ |
| Journal d'audit | 🟡 Seulement pour les corrections et annulations | — |

**Autres incohérences relevées :**
- L'appli affiche « AgriPlatform » sur l'écran de connexion, mais « SenAgriLink » ailleurs.
- Sur le site, plusieurs erreurs s'affichent comme des listes vides (« Aucune commande ») au lieu d'un message.
- Un utilisateur qui ouvre une page d'un autre rôle voit le formulaire de connexion, sans explication.

### 7.6 Bilan (fonctions des §7.1 à 7.4)

| État | Nombre |
|---|---|
| ✅ Fait et vérifié | 5 sur 45 |
| 🟡 À moitié fait | 11 |
| ⚠️ Présent mais ne fonctionne pas réellement | 10 |
| ❌ Absent | 19 |

---

## 8. Plan de réalisation

**Ordre suivi :** votre §12 (fiabilité des stocks et des commandes d'abord, puis les circuits, puis le GPS, puis l'expérience).

**Pour chaque lot :**
- une branche séparée ;
- des tests des règles métier, des droits d'accès et des cas d'échec ;
- une démonstration dans la préproduction ;
- **votre validation avant de passer au lot suivant**.

| Lot | Contenu | Visible pour vous |
|---|---|---|
| **1. Fondations** | Stock physique, réservé et disponible. Journal des mouvements. Prix au kilo fixé par le producteur et figé à la commande. Historique automatique de chaque étape. Lot verrouillé après réception. Suppression de la double entrée de stock. Seuils d'alerte modifiables. | Le catalogue affiche le vrai disponible, au bon prix. Le gestionnaire voit le journal des mouvements de chaque stock. |
| **2. Circuit A complet** | Profil avec position sur une carte. Les étapes du §4 (dont le mode « je livre moi-même »). Acceptation ou refus de mission. Réception totale, partielle ou refusée. Notifications au gestionnaire. Code du lot affiché et vérifié. Traçabilité de l'acheminement. | Le parcours récolte, dépôt et réception fonctionne de bout en bout, avec plusieurs comptes |
| **3. Circuit B complet** | Panier. Les étapes du §5 (préparation, chargement, sortie de stock). Réception par l'acheteur avec code à 4 chiffres. Anomalies et litiges. Incidents (§5.2) pour les deux circuits. | Le parcours commande, livraison et réception fonctionne de bout en bout, incidents compris |
| **4. GPS fiable** | Positions liées à la mission. Suivi en arrière-plan sur Android, avec notification. Positions gardées hors connexion puis renvoyées. GPS pour les deux circuits. Accès du gestionnaire. Heure d'arrivée estimée et trajet routier. Bouton « Itinéraire ». Fond de carte autorisé. Suppression des positions après 30 jours. | Suivi « comme Yango », testé sur un vrai téléphone, écran verrouillé |
| **5. Écrans par rôle** | Page « À faire » pour chaque rôle. Fiches commande et lot. Toutes les actions sur l'appli (gestionnaire et producteur compris). Tableau des exceptions de l'administrateur. QR code. Libellés et nom unifiés. Vrais messages d'erreur. | Chaque rôle voit immédiatement ce qu'il doit faire, sur le site comme sur l'appli |
| **6. Notifications et comptes** | Notifications push. Liste des notifications sur l'appli. Validation des comptes par l'administrateur. Journal d'audit complet. | — |
| **7. Recette et mise en ligne** | Préproduction, liste de contrôle, signature de l'application. Ce travail est déjà préparé : voir `docs/livraison/`. | Version installable officielle |

**Pour donner un ordre de grandeur :** chaque lot représente plusieurs jours de travail, plus les tests sur téléphone réel, qui ne peuvent être faits que par vous. Les lots 1 à 3 sont le cœur : sans eux, le GPS et les beaux écrans reposeraient sur des données fausses.

**Ce qui dépend de vous :**
- répondre aux questions du §6 (au minimum Q1 à Q7 pour démarrer le lot 1) ;
- tester sur un téléphone Android à la fin des lots 2 à 5 ;
- **à vérifier plus tard :** le fichier `render.yaml` du dépôt décrit une base Render, alors que votre production utilise Neon. Les réglages réels du tableau de bord Render diffèrent donc du dépôt. Je les vérifierai avec vous avant toute mise en ligne.

---

*Rapports d'analyse détaillés (fichier et ligne de chaque constat) disponibles sur demande. Les principaux constats bloquants ont été revérifiés directement dans le code.*
