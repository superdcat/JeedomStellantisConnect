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

Les identifiants ne sont **pas** distribués par Stellantis : ils sont embarqués dans l'application
mobile de chaque marque. La méthode éprouvée, issue du projet open source
[psa_car_controller](https://github.com/flobz/psa_car_controller), consiste à les extraire de l'APK :

1. Téléchargez l'APK de l'application mobile de **votre marque** (par exemple depuis le dépôt
   [flobz/psa_apk](https://github.com/flobz/psa_apk), qui archive les versions compatibles).
2. Exécutez le script `app_decoder.py` fourni par psa_car_controller sur cet APK :
   ```
   python3 app_decoder.py <fichier.apk>
   ```
3. Le script affiche notamment le `client_id` et le `client_secret` de l'application. Reportez-les
   tels quels dans la configuration du plugin, et sélectionnez la marque correspondant à l'APK utilisé.

Cette extraction se fait **en dehors de Jeedom** (sur votre ordinateur) ; le plugin ne télécharge ni
n'analyse aucun APK. Les identifiants n'expirent pas, l'opération n'est à faire qu'une fois.

## Étapes suivantes

Une fois le plugin configuré, connectez votre compte (bouton d'authentification), puis lancez la
découverte des véhicules — ces étapes sont décrites dans les sections correspondantes de cette
documentation au fur et à mesure des versions du plugin.
