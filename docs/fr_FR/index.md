# Plugin Stellantis Connect

Ce plugin connecte vos véhicules **Stellantis / ex-Groupe PSA** (Peugeot, Citroën, DS, Opel, Vauxhall)
à Jeedom : remontée de la télémétrie (batterie, charge, autonomie, carburant, position GPS,
kilométrage…) via l'API « connected car » utilisée par les applications mobiles officielles
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ Le plugin utilise l'API **consommateur** de Stellantis, la même que les applications mobiles.
> Stellantis ne fournit pas d'accès développeur aux particuliers : vous devez récupérer vous-même les
> identifiants de l'application mobile de votre marque (voir « Obtenir les identifiants » ci-dessous).

## Configuration du plugin

Rendez-vous dans `Plugins → Gestion des plugins → Stellantis Connect → Configuration`. Les paramètres
sont communs à tous vos véhicules d'une même marque :

| Champ | Description |
|---|---|
| **Marque** | La marque de vos véhicules (Peugeot, Citroën, DS, Opel ou Vauxhall). Elle détermine le serveur d'authentification et le domaine utilisés — choisissez la marque correspondant à l'application mobile dont proviennent vos identifiants. |
| **Client ID** | Identifiant OAuth2 de l'application mobile, extrait de l'APK (voir ci-dessous). |
| **Client Secret** | Secret OAuth2 associé, extrait de l'APK. Il est **stocké chiffré** dans Jeedom et n'apparaît jamais dans les logs. |
| **Pays** | Code pays à 2 lettres (ex. `fr`), utilisé pour construire l'URL de redirection par défaut. |
| **URL de redirection** | `redirect_uri` OAuth2 de l'application mobile (ex. `mymap://oauth2redirect/fr`). Laissez vide pour utiliser la valeur par défaut de la marque. Si votre outil d'extraction vous a fourni une valeur, utilisez-la. |

Tant que le Client ID et le Client Secret ne sont pas renseignés, la page affiche un bandeau
« Plugin non configuré » et les autres fonctions du plugin restent inactives.

## Obtenir les identifiants (Client ID / Client Secret)

Les identifiants ne sont **pas** distribués par Stellantis : ils sont embarqués dans l'APK de
l'application mobile de chaque marque (dans un fichier interne `parameters.json`, sous les clés
`cvsClientId` et `cvsSecret`). Il faut donc les extraire **une fois**, sur un ordinateur — le plugin,
lui, ne télécharge ni n'analyse aucun APK.

> Les identifiants dépendent de la **marque** et du **pays** de votre compte : extrayez ceux qui
> correspondent à l'application que vous utilisez et à votre pays. Ils n'expirent pas.

### Méthode recommandée : extraction directe (sans connexion au compte)

Cette méthode récupère **uniquement** le Client ID et le Client Secret ; la connexion à votre compte
se fera ensuite dans Jeedom (section suivante), il est donc inutile de se connecter ici. Sur une
machine disposant de **Python 3.11 ou plus récent** (votre PC, ou votre Jeedom si sa version de Python
convient) :

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

### Autre méthode : assistant graphique de psa_car_controller

Si vous préférez une interface web à la ligne de commande, l'assistant de psa_car_controller télécharge
l'APK et extrait les identifiants automatiquement — mais il vous fait **aller jusqu'au bout d'une
connexion OAuth** (la même que l'étape « Connexion au compte » ci-dessous) avant d'écrire les valeurs
sur le disque :

1. `pip3 install psa-car-controller`, puis lancez `psa-car-controller -l 0.0.0.0 --web-conf`.
2. Ouvrez `http://<adresse-de-la-machine>:5000` et renseignez la marque, l'e-mail, le mot de passe du
   compte et le code pays.
3. Terminez la procédure de connexion (elle utilise la même récupération de code via F12 décrite plus bas).
4. Ouvrez le fichier `config.json` créé dans le dossier de travail : recopiez-en les valeurs `client_id`
   et `client_secret` dans le plugin.

> Cet assistant installe et fait tourner un second outil (et vous fait vous connecter deux fois, une
> fois ici puis une fois dans Jeedom). Le plugin **n'en dépend pas** ensuite : c'est pourquoi la méthode
> directe ci-dessus est préférable.

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
   > Si un message « code invalide, expiré ou déjà utilisé » ou « ré-authentification requise »
   > apparaît, régénérez l'URL (étape 1) et recollez la nouvelle URL rapidement. Un message
   > mentionnant le *realm* signifie que la **marque choisie ne correspond pas au compte**.

L'état passe à « Connecté au compte ». Vous pouvez vérifier le bon fonctionnement à tout moment via
le bouton **Tester la connexion** de la page du plugin (`Plugins → Objets connectés → Stellantis
Connect`), qui affiche le nombre de véhicules trouvés sur le compte. Le plugin gère ensuite seul le
rafraîchissement du jeton d'accès ; vous n'aurez à refaire cette procédure que si la connexion est révoquée (message
« ré-authentification requise »), après un changement de marque ou d'identifiants, ou après un
vidage complet du cache Jeedom.

## Étapes suivantes

La découverte des véhicules et la remontée de la télémétrie sont décrites dans les sections
correspondantes de cette documentation au fur et à mesure des versions du plugin.
