# Plugin Stellantis Connect

Ce plugin connecte vos véhicules **Stellantis / ex-Groupe PSA** (Peugeot, Citroën, DS, Opel, Vauxhall)
à Jeedom : remontée de la télémétrie (batterie, charge, autonomie, carburant, position GPS,
kilométrage, portes/ouvrants, pression des pneus, entretien…) et, une fois le pilotage à distance
activé, **commandes à distance** (réveil, charge, préconditionnement, verrouillage, klaxon, feux) — via
l'API « connected car » utilisée par les applications mobiles officielles (MyPeugeot, MyCitroën, MyDS,
MyOpel, MyVauxhall).

> Les noms et couleurs de marques (Peugeot, Citroën, DS, Opel, Vauxhall) sont cités à titre
> d'identification ; ce plugin n'est ni affilié ni approuvé par les constructeurs.

## Avertissement — API non officielle & risques (ToS)

> ⚠️ **À lire avant toute utilisation.**

Ce plugin repose sur l'API **consommateur** de Stellantis — celle qu'utilisent les applications
mobiles — et non sur une API développeur officielle : Stellantis n'en fournit pas aux particuliers.
Cette API a été **reverse-engineered** par la communauté (notamment le projet
[`psa_car_controller`](https://github.com/flobz/psa_car_controller), dont ce plugin réutilise certains
éléments sous licence GPL-3, et dont le comportement observé sert de référence).

Conséquences à connaître avant d'activer le plugin :

- Elle peut **cesser de fonctionner sans préavis**, en totalité ou en partie, à la suite d'un
  changement décidé par Stellantis (aucune garantie de continuité ni de délai de correction).
- Son usage se fait **aux risques de l'utilisateur**, y compris les risques **légaux et
  contractuels** : c'est à vous de vérifier que cet usage reste compatible avec les conditions
  d'utilisation (ToS) de votre compte de marque.
- Le plugin est fourni **sans aucune garantie**, conformément à la licence **GPL-3** qui le régit.
- L'extraction de vos identifiants (Client ID / Client Secret) — qu'elle soit automatique ou
  manuelle — se fait sous votre seule responsabilité.

## Configuration du plugin

Rendez-vous dans `Plugins → Gestion des plugins → Stellantis Connect → Configuration`. Les paramètres
du fieldset « Compte principal (pilotage à distance) » sont communs à tous les véhicules de ce compte :

| Champ | Description |
|---|---|
| **Marque** | La marque de vos véhicules (Peugeot, Citroën, DS, Opel ou Vauxhall). Elle détermine le serveur d'authentification et le domaine utilisés — choisissez la marque correspondant à l'application mobile dont proviennent vos identifiants. |
| **Client ID** | Identifiant OAuth2 de l'application mobile. Rempli automatiquement par **Extraire automatiquement**, ou saisi manuellement (voir « Obtenir les identifiants » ci-dessous). |
| **Client Secret** | Secret OAuth2 associé, obtenu de la même façon. Il est **stocké chiffré** dans Jeedom et n'apparaît jamais dans les logs. |
| **Pays** | Code pays à 2 lettres (ex. `fr`), utilisé pour construire l'URL de redirection par défaut et pour l'extraction automatique. |
| **URL de redirection** | `redirect_uri` OAuth2 de l'application mobile (ex. `mymap://oauth2redirect/fr`). Laissez vide pour utiliser la valeur par défaut de la marque. |

Tant que le Client ID et le Client Secret ne sont pas renseignés, la page affiche un bandeau
« Plugin non configuré » et les autres fonctions du plugin restent inactives.

## Obtenir les identifiants (Client ID / Client Secret)

Les identifiants ne sont **pas** distribués par Stellantis : ils sont embarqués dans l'APK de
l'application mobile de chaque marque (dans un fichier interne `parameters.json`, sous les clés
`cvsClientId` et `cvsSecret`), et dépendent de la **marque** et du **pays** de votre compte. Deux
méthodes permettent de les récupérer ; elles ne dépendent l'une de l'autre en rien.

### Méthode 1 (recommandée, en un clic) : extraction automatique dans Jeedom

Le plugin sait lui-même télécharger l'application mobile de votre marque et en extraire les
identifiants, sans outil externe à installer :

1. Dans la configuration du plugin, sélectionnez la **Marque** et renseignez le **Pays** (ex. `fr`).
2. Cliquez sur le bouton **Extraire automatiquement**.
3. Confirmez l'avertissement affiché (« Cette API n'est pas officielle. Continuer ? ») : le
   téléchargement de l'application (~100 Mo) démarre, hébergé sur un dépôt communautaire tiers.
4. Patientez le temps du téléchargement et de l'extraction. En cas de succès, les champs **Client ID**
   et **Client Secret** se remplissent automatiquement.

> ℹ️ **Où ça s'exécute, et quand préférer l'autre méthode.** Cette extraction a lieu **sur la box
> Jeedom elle-même** : elle réutilise l'interpréteur **Python 3** déjà installé pour le démon de
> pilotage à distance, et télécharge directement sur la box l'archive de l'application mobile
> (**~100 Mo**). Sur un **Raspberry Pi équipé d'une carte SD** (où mieux vaut ménager l'espace et les
> écritures) — ou **en cas d'échec** — préférez la **Méthode 2** ci-dessous, à réaliser sur un
> ordinateur.

Le champ avancé **URL de l'application mobile (avancé)** (`apk_url`) permet d'indiquer une autre URL
d'archive `.apk.bz2` si le dépôt communautaire par défaut est indisponible ou a été déplacé ; laissez-le
vide dans le cas général.

### Méthode 2 (repli) : extraction manuelle sur un ordinateur

Cette méthode récupère **uniquement** le Client ID et le Client Secret, sur une machine de votre choix
disposant de **Python 3.11 ou plus récent** (typiquement votre PC) ; la connexion à votre compte se
fera ensuite dans Jeedom (section « Connexion au compte » suivante), il est donc inutile de se
connecter ici :

```bash
# 1. Installer l'outil d'extraction (il apporte aussi sa dépendance « androguard »)
pip3 install psa-car-controller

# 2. Télécharger puis décompresser l'APK de VOTRE marque
#    (exemple Peugeot ; remplacez par mycitroen / myds / myopel / myvauxhall)
curl -L -o app.apk.bz2 https://github.com/flobz/psa_apk/raw/main/mypeugeot.apk.bz2
bunzip2 app.apk.bz2      # produit le fichier app.apk

# 3. Extraire les identifiants (remplacez FR par le code pays de VOTRE compte)
python3 - <<'PY'
from psa_car_controller.psa.setup.apk_parser import ApkParser
p = ApkParser("app.apk", "FR")
p.retrieve_content_from_apk()
print("Client ID     =", p.client_id)
print("Client Secret =", p.client_secret)
PY
```

Recopiez les deux valeurs affichées dans la configuration du plugin, et sélectionnez la marque
correspondant à l'APK utilisé.

APK par marque (dépôt [flobz/psa_apk](https://github.com/flobz/psa_apk), qui archive des versions
connues pour fonctionner) :

| Marque | Fichier à télécharger |
|---|---|
| Peugeot | `mypeugeot.apk.bz2` |
| Citroën | `mycitroen.apk.bz2` |
| DS | `myds.apk.bz2` |
| Opel | `myopel.apk.bz2` |
| Vauxhall | `myvauxhall.apk.bz2` |

> **Variante : assistant graphique de `psa_car_controller`.** Si vous préférez une interface web à la
> ligne de commande, `pip3 install psa-car-controller` puis `psa-car-controller -l 0.0.0.0 --web-conf`
> ouvre un assistant (`http://<adresse-de-la-machine>:5000`) qui télécharge l'APK et extrait les
> identifiants automatiquement — mais il vous fait aller jusqu'au bout d'une **connexion OAuth**
> complète (la même procédure que « Connexion au compte » ci-dessous) avant d'écrire un fichier
> `config.json` dont vous recopierez les valeurs `client_id`/`client_secret`. Cet assistant installe et
> fait tourner un second outil, et vous fait vous connecter deux fois (une fois là, une fois dans
> Jeedom) : la ligne de commande ci-dessus est donc préférable dans le cas général.

## Connexion au compte

Une fois la configuration sauvegardée, connectez le plugin à votre compte (section « Connexion au
compte » de la page de configuration). Cette connexion se fait de préférence depuis un **ordinateur
équipé d'un navigateur avec outils de développement** :

1. Cliquez sur **Générer l'URL d'autorisation**, puis ouvrez le lien affiché dans votre navigateur.
2. Connectez-vous avec les identifiants de l'application mobile de votre marque (e-mail + mot de passe).
   > ⚠️ Les comptes PSA limitent le **mot de passe à 16 caractères** : si le vôtre est plus long, la
   > connexion peut échouer sur le site de la marque.
3. Après connexion, le navigateur tente d'ouvrir l'application mobile (adresse commençant par
   `mymap://…`, `mymacsdk://…` selon la marque) et affiche une **page d'erreur : c'est normal**, le
   navigateur ne sait pas ouvrir ce type d'adresse.
   - **Cas simple** : la barre d'adresse contient l'URL complète `…://oauth2redirect/…?code=…`.
     Copiez-la **en entier**.
   - **Si la barre d'adresse n'affiche rien d'exploitable** : ouvrez les outils de développement
     (touche **F12**) → onglet **Réseau (Network)**, puis déclenchez la redirection. Repérez la ligne
     dont l'adresse commence par le schéma de votre marque (`mymap://…`) et copiez la valeur du
     paramètre **`code`** (une suite de **36 caractères**).
4. Collez l'URL complète (ou, à défaut, le `code` seul) dans le champ **Code d'autorisation**, puis
   cliquez sur **Valider le code** **sans attendre** : le code n'est valable que quelques instants et
   à usage unique.
   > Si un message signale un **code refusé (invalide, expiré ou déjà utilisé)** ou qu'une **nouvelle
   > connexion est nécessaire**, régénérez l'URL (étape 1) et recollez la nouvelle URL rapidement. Un
   > message mentionnant le *realm* signifie que la **marque choisie ne correspond pas au compte**.

L'état passe à « Connecté au compte ». Vous pouvez vérifier le bon fonctionnement à tout moment via
le bouton **Tester la connexion** de la page du plugin (`Plugins → Objets connectés → Stellantis
Connect`), qui affiche le nombre de véhicules trouvés sur le compte. Le plugin gère ensuite seul le
rafraîchissement du jeton d'accès ; vous n'aurez à refaire cette procédure que si la connexion est
révoquée (un message signale alors qu'une reconnexion est nécessaire, sur la page du plugin et dans
les messages Jeedom), après un changement de marque ou d'identifiants, ou après un vidage complet du
cache Jeedom.

## Pilotage à distance — activation OTP

Le simple raccordement de votre compte (section précédente) ne suffit qu'à la **lecture** de la
télémétrie. Pour débloquer les **commandes à distance** (réveil, démarrage/arrêt de charge,
programmation de la charge, préconditionnement, verrouillage/déverrouillage, klaxon, feux), une
activation supplémentaire est nécessaire : elle se fait dans le fieldset **« Pilotage à distance
(activation OTP) »** de la page de configuration.

> **Prérequis** : le compte principal doit déjà être **connecté** (section « Connexion au compte »
> ci-dessus) — le numéro de téléphone associé à ce compte est utilisé pour recevoir le SMS
> d'activation.

Procédure en 3 étapes :

1. Cliquez sur **Envoyer le SMS d'activation** (« 1. Recevoir le SMS ») et confirmez : un SMS
   contenant un code est envoyé au numéro associé à votre compte de marque.
2. Saisissez ce code dans le champ **Code reçu par SMS** (« 2. Code reçu par SMS »).
3. Saisissez votre **Code PIN de l'application** (« 3. Code PIN de l'application » — le code à
   4 chiffres que vous utilisez dans l'application mobile de votre marque), puis cliquez sur
   **Activer le pilotage à distance**.

L'état affiché passe à « Activé ».

> ⚠️ **Quotas durs et définitifs côté Stellantis** : **6 codes par 24 h** et **20 activations SMS par
> compte, à vie** — ces compteurs ne sont **jamais remis à zéro**. N'utilisez cette activation que
> lorsque vous êtes prêt à aller au bout, et évitez de la répéter sans raison.

Le jeton distant a une durée de vie technique très courte (**~15 minutes**). Le plugin le **renouvelle
automatiquement et silencieusement à chaque passage du cron**, par un simple rafraîchissement — **sans
code OTP ni SMS** — tant que cette chaîne de renouvellement fonctionne : vous ne devriez normalement
**jamais avoir à intervenir vous-même**.

Si ce renouvellement automatique **échoue durablement** (jeton de renouvellement distant invalide ou
révoqué), l'état passe à « Expiré — renouvellement nécessaire ». Dans ce cas seulement, cliquez sur le
bouton **Renouveler le jeton distant** : il réutilise l'appareil OTP déjà enregistré, **sans nouveau
SMS**, mais génère un nouveau code OTP et **consomme donc 1 unité du quota strict de 6 codes / 24 h**
évoqué ci-dessus — n'utilisez ce bouton que lorsque l'état l'indique réellement. Ne refaites
l'activation complète en 3 étapes que si ce renouvellement échoue à son tour.

> Le pilotage à distance (OTP, commandes) n'est disponible que sur le **compte principal** (le
> premier compte configuré) — les comptes secondaires (section suivante) restent en lecture seule.

## Comptes secondaires (multi-marques, lecture seule)

Vous pouvez rattacher jusqu'à deux comptes/marques supplémentaires (sections repliables « Compte
secondaire 2 »/« Compte secondaire 3 », visibles une fois le compte principal configuré) : même
procédure d'obtention des identifiants et de connexion que ci-dessus, mais ces comptes restent en
**lecture seule** (télémétrie uniquement) — aucune activation OTP ni commande à distance n'y est
disponible.

## Fonctions disponibles

- **Télémétrie** : batterie/état de charge, autonomie (électrique, carburant, totale), position GPS,
  kilométrage, état des portes/ouvrants (portes, coffre, capot…), pression des pneus (alerte), entretien
  (échéance de révision).
- **Commandes à distance** (compte principal, après activation OTP) : réveil, démarrage/arrêt de la
  charge et programmation d'horaire, préconditionnement climatique, verrouillage/déverrouillage,
  klaxon, feux.
- **Panneau carte « Mes véhicules »** : vue d'ensemble de la position de vos véhicules, accessible
  depuis le menu d'accueil de Jeedom.
- **Geofencing / zone domicile** : détection « au domicile » / distance au domicile, à partir d'une
  zone domicile unique configurée pour le foyer.
- **Alertes véhicule** : remontée générique des alertes constructeur (pneus, AdBlue, lave-glace,
  voyants…) sous forme de commandes exploitables en scénario.
- **Statistiques de charge** : détection des sessions de charge, énergie/durée/coût estimés.

## Limites & bonnes pratiques

- **Fraîcheur des données** : la télémétrie est obtenue par **interrogation périodique** (~5 minutes
  par défaut) — l'API Stellantis ne propose pas de notification en temps réel (« push »). Les
  informations affichées peuvent donc avoir quelques minutes de retard.
- **Batterie 12 V** : réveiller un véhicule (manuellement ou via le réveil automatique adaptatif,
  désactivé par défaut) sollicite la batterie de servitude 12 V. Un réveil trop fréquent peut la
  fragiliser ; laissez la cadence par défaut sauf besoin réel.
- **Anti-ban** : le plugin applique volontairement des quotas et délais (cooldowns) sur les appels à
  l'API et les commandes, pour limiter le risque de blocage temporaire du compte côté Stellantis. Ne
  cherchez pas à forcer des rafraîchissements répétés au-delà de ce que propose l'interface.
- **Mode privacy** : si le partage de données/localisation est coupé côté véhicule (paramètre de
  confidentialité de l'application mobile), le plugin se met automatiquement en veille pour ce
  véhicule (moins d'interrogations) et signale la situation — **ce n'est pas une panne du plugin**.
- **Pilotage à distance mono-compte** : les commandes à distance ne fonctionnent que sur le compte
  principal ; les comptes secondaires restent en lecture seule (cf. ci-dessus).
- **API non officielle** : comme rappelé plus haut, cette intégration peut cesser de fonctionner sans
  préavis en cas de changement côté Stellantis.
