# Specs — Plugin Jeedom IMOU (Solution A : API directe en PHP)

> Décision d'architecture : voir `.memory/analyse/imou-api-vs-imouapi.md`.
> Approche : appeler l'**IMOU Open API** directement en PHP, sans démon, sans Python.

## Organisation

- **`MVP/`** — le socle livrable en premier : se connecter, synchroniser les caméras,
  allumer/éteindre, activer/désactiver la surveillance, rafraîchir l'état.
- **`post-mvp/`** — toutes les UC suivantes, regroupées par domaine fonctionnel
  (`01-…` à `06-…`) + les chantiers transverses (`07-…`, `08-…`). Ordre = roadmap
  **suggérée** (ajustable).

```
.memory/specs/
├── MVP/                            Socle (tâches 01→10, ordre interne strict)
└── post-mvp/                       UC suivantes (regroupées par domaine)
    ├── 10-pilotage-avance/         11 switches dynamiques (✅), 12 projecteur/sirène (✅ sirène via IoT),
    │                               13 commandes-iot-et-proprietes (socle IoT), 14 projecteur minuterie, 15 PTZ (✅),
    │                               16 commandes affichées par défaut
    ├── 20-video-images/            21 snapshot (❌ RETIRÉE — affichage dashboard bloqué par CSP + redondant avec le live ; capture reste via UC55), 22 flux live (✅), 23 vision nocturne,
    │                               24 réglages image (flip/WDR/OSD/LED…),
    │                               25 widgets d'affichage (✅ pavé PTZ + live-snapshot frames HLS/ffmpeg same-origin + boutons soignés ; ✅ limiteur de concurrence multi-caméras FIFO, config liveMaxConcurrent défaut 3),
    │                               26 live en plein écran (✅ clic pour agrandir, 100 % front réutilise UC25 ; tuile en overlay prioritaire sur le limiteur),
    │                               27 panneau caméras (PAGE dédiée dans le menu : grille de live + barre PTZ sans zoom / sirène / projecteur, activable via config plugin)
    ├── 30-alarmes-evenements/      31 messages alarme, 32 zones/sensibilité, 33 plans, 34 détection humaine/IA, 35 temps réel (push)
    ├── 40-enregistrement-stockage/ 41 carte SD, 42 enregistrement cloud, 43 relecture
    ├── 50-gestion-appareils/       51 identifiants deviceId/productId (✅), 52 redémarrage/renommage (endpoint restartDevice), 53 firmware, 54 association,
    │                               55 miniature de la caméra (source au choix cliché live/cover, récup. immédiate + aperçu, rafraîchissement manuel), 56 affichage nom de modèle (code + nom commercial),
    │                               57 batterie & réveil appareil dormant (getDevicePowerInfo / wakeUpDevice)
    ├── 60-controle-acces/          61 sonnette vidéo, 62 ouverture de porte (selon matériel)
    ├── 70-supervision-robustesse/  71 online/santé (+ skip-offline quota), 72 rate limiting/retries/i18n + redirection datacenter (transverse), 73 synchronisation sélective par caméra (✅),
    │                               74 statistiques d'utilisation / quota d'appels API (✅ quota MENSUEL global ≈30000 free tier + config + alerte),
    │                               75 rafraîchissement auto par défaut à la création (aligné UC16),
    │                               76 ❌ optimisation polling IoT (CLÔTURÉE sans code : getIotDeviceDetailInfo = découverte de capacités, PAS snapshot de valeurs),
    │                               77 régulation auto de la fréquence de refresh selon le budget quota (✅ calcul événementiel + recalibrage nocturne, consomme UC74),
    │                               78 estimation conso data flux live (informatif/estimatif : temps visionnage × débit supposé, quota défaut 3 Go, reset = jour UC74 ; vraie conso = portail IMOU)
    └── 80-livraison/               81 recette manuelle, 82 packaging/doc utilisateur, 83 icône du plugin
```

> **Numérotation** : dossiers par dizaines (10, 20, …), fichiers par unités dans chaque dossier
> (`11-…`, `12-…`). Les UC déjà livrées sont aux premiers numéros (11, 12, 51).

> **Note IoT « Things » (2026-06-17)** : certaines caméras (`productId` non vide) exposent un modèle
> IoT (`getProductModel`) avec des **services** (`iotDeviceControl`) et **propriétés** enum/int au-delà
> des capability switches. Mécanisme + capacités découvertes : `.memory/analyse/imou-iot-things-model.md`.
> Pré-requis transverse aux UC IoT : `10-pilotage-avance/13-commandes-iot-et-proprietes.md`.

## MVP — ordre des tâches (dépendances strictes)

| # | Titre | Dépend de |
|---|---|---|
| 01 | Configuration globale du plugin | — |
| 02 | Client HTTP IMOU bas niveau | 01 |
| 03 | Gestion du token d'accès | 02 |
| 04 | Test de connexion | 03 |
| 05 | Découverte des appareils | 03 |
| 06 | Création / mise à jour des équipements | 05 |
| 07 | Lecture des capacités & création des commandes | 06 |
| 08 | Action : allumer / éteindre la caméra | 07 |
| 09 | Action : activer / désactiver la surveillance | 07 |
| 10 | Rafraîchissement périodique (cron5) | 07 |

> Les UC des dossiers thématiques dépendent toutes du socle MVP (client HTTP 02, token 03,
> équipements 06, commandes 07). Elles sont **indépendantes entre elles** sauf mention contraire.

## Conventions transverses

- Langue FR ; i18n `{{...}}` (HTML/JS) et `__('...', __FILE__)` (PHP).
- Classes `imou` / `imouCmd` dans `core/class/imou.class.php` ; tous les appels HTTP passent par
  la brique unique `imouApi` (MVP/02).
- Logs via `log::add('imou', …)` ; **jamais** de secret/token en clair.
- Pas de démon, pas de Python : tout en PHP (cron Jeedom + `cmd::execute()`).
- Une UC = un commit/PR, tests verts entre chaque.

## ⚠️ Statut de fiabilité des specs

- **MVP** : endpoints et paramètres vérifiés lors de l'étude (signature, accessToken,
  set/getDeviceCameraStatus, liste des appareils).
- **Dossiers thématiques** : domaines **confirmés présents** dans l'API IMOU (cf. analyse), mais
  les noms exacts d'endpoints/paramètres sont à **valider dans la doc** au moment de l'implémentation
  (et/ou via le code source de `imouapi`). Chaque spec le signale.
