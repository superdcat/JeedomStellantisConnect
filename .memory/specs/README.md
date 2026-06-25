# Specs — Plugin Jeedom Stellantis (véhicules connectés PSA)

> Décision d'architecture : voir `.memory/analyse/stellantis-api-architecture.md`.
> Approche : **API consommateur** PSA/Stellantis (OAuth2 PKCE + REST v4). **MVP = lecture seule en PHP
> pur, sans démon** (polling cron) ; **commandes à distance = post-MVP via démon Python MQTT**.

## Organisation

- **`MVP/`** — socle livrable en premier : se connecter (OAuth2), découvrir les véhicules, remonter la
  **télémétrie** (batterie, charge, autonomie, carburant, position, km, portes), rafraîchir par cron.
  **100 % lecture, 100 % PHP.**
- **`post-mvp/`** — UC suivantes par domaine. Ordre = roadmap **suggérée** (ajustable).

```
.memory/specs/
├── MVP/                            Socle lecture (01→10, ordre interne strict, PHP/REST)
└── post-mvp/
    ├── 10-commandes-distance/      ⚠️ introduit le DÉMON Python MQTT. 11 socle démon, 12 OTP/remote token,
    │                               13 wakeup, 14 charge, 15 préconditionnement, 16 portes, 17 klaxon/feux,
    │                               18 retour d'état asynchrone
    ├── 20-energie-charge/          21 détail batterie/charge, 22 programmation de charge, 23 carburant/hybride,
    │                               24 suivi/statistiques de charge
    ├── 30-localisation-trajets/    31 position GPS, 32 panneau carte « Mes véhicules », 33 historique trajets,
    │                               34 geofencing/alertes de zone
    ├── 40-entretien-alertes/       41 kilométrage & entretien, 42 pression pneus, 43 alertes véhicule
    │                               (AdBlue/lave-glace/révision), 44 ouvrants détaillés
    ├── 50-gestion-vehicules/       51 identité véhicule (VIN/marque/modèle), 52 image du modèle,
    │                               53 multi-véhicules/comptes, 54 multi-marques
    ├── 70-supervision-robustesse/  71 santé & fraîcheur, 72 rate-limiting/anti-ban, 73 protection batterie 12V,
    │                               74 renouvellement & alertes de token, 75 mode privacy, 76 sync sélective,
    │                               77 statistiques d'appels API
    └── 80-livraison/               81 recette manuelle, 82 packaging/doc (démon, paho-mqtt), 83 icône,
                                    84 i18n multilingue
```

> **Numérotation** : dossiers par dizaines, fichiers par unités. Chaque UC = un fichier fonctionnel
> `NN-nom.md` ; les UC dont l'implémentation est précisée ont un compagnon technique `NN-nom-tech.md`.

## MVP — ordre des tâches (dépendances strictes)

| # | Titre | Dépend de |
|---|---|---|
| 01 | Configuration du plugin (marque, client_id/secret, redirect_uri) | — |
| 02 | Client HTTP REST bas niveau (`stellantisApi`) | 01 |
| 03 | Authentification OAuth2 PKCE & gestion du token | 02 |
| 04 | Test de connexion | 03 |
| 05 | Découverte des véhicules | 03 |
| 06 | Création / mise à jour des équipements (clé VIN) | 05 |
| 07 | Commandes info de télémétrie | 06 |
| 08 | Rafraîchissement périodique (cron) | 07 |
| 09 | État de connexion & fraîcheur de la donnée | 07 |
| 10 | Robustesse & gestion d'erreurs (token, rate-limit, dégradé) | 07 |

> Toutes les UC post-MVP dépendent du socle MVP (client 02, token 03, équipements 06, infos 07). Le
> dossier `10-commandes-distance` dépend en plus du **démon** (UC11) et de l'**OTP** (UC12).

## Conventions transverses

- Langue FR ; i18n `{{...}}` (HTML/JS) et `__('...', __FILE__)` (PHP).
- Classes `stellantis` / `stellantisCmd` dans `core/class/stellantis.class.php` ; **tout appel REST**
  passe par la brique unique `stellantisApi` (MVP/02).
- Logs via `log::add('stellantis', …)` ; **jamais** de secret/token/`client_secret` en clair.
- **MVP sans démon** (PHP + cron + REST) ; **démon Python MQTT** uniquement à partir de
  `post-mvp/10-commandes-distance`.
- **Guardrails obligatoires** (anti-ban / batterie 12 V) dès qu'on touche au polling/wakeup
  (cf. analyse § 1.4, UC72/73).
- Une UC = un commit/PR, vérifications vertes entre chaque.

## ⚠️ Statut de fiabilité des specs

- **MVP** : le **flow OAuth2 PKCE** (endpoints par marque, realms, refresh) et les **endpoints REST v4**
  (`/user/vehicles`, `/status`, `/lastPosition`) sont **confirmés** par la recherche (doc officielle +
  `psa_car_controller`). Les **noms de champs exacts** du statut sont à **reconfirmer** contre une
  réponse réelle / les modèles `psa_car_controller` (cf. `.memory/analyse/stellantis-data-model.md`).
- **Commandes (post-MVP)** : domaines **confirmés** (MQTT `mwa.mpsa.com:8885`, payloads connus) mais les
  **payloads exacts** et le contrat OTP sont à valider contre le code de référence au moment de coder
  (cf. `.memory/analyse/stellantis-implementations-reference.md`). Chaque spec le signale.
- ⚠️ **Risque produit** : l'API consommateur est reverse-engineered (ToS, instabilité backend Stellantis).
  Le plugin doit être **résilient** et le **risque documenté pour l'utilisateur** (cf. analyse).
