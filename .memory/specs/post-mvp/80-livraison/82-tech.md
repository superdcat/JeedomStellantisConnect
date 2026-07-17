# Spec technique — 82 (Packaging & documentation utilisateur)

> **Nature :** feature à dominante **documentaire** (doc utilisateur multilingue) + **vérification de
> packaging** + une **correction de cohérence UI** (chaînes de la page de config). **Aucune logique
> PHP/JS nouvelle**, aucun endpoint/action AJAX/topic MQTT créé.

## Architecture

Quatre volets.

### Volet A — Packaging (VÉRIFIER, ne rien régresser)
- `plugin_info/packages.json` : **déjà correct** — `paho-mqtt` **`{"version":"1.6.1"}`** (pin exact ; **jamais** `<2.0.0` ni opérateur dans la clé — piège shell documenté, cf. mémoire `jeedom-packagesjson-shell-pitfall`), `requests` `2.32.3`, `pycryptodomex` `3.20.0`. **Ne PAS toucher.** ⚠️ Le texte de la spec fonctionnelle (`<2.0.0`, `cryptography`, `jeedomdaemon~=1.2.0`) est **périmé** → ne pas l'appliquer.
- `plugin_info/info.json` : **complet** (`category devicecommunication`, `require 4.2`, os 10–12.99, `hasDependency/hasOwnDeamon:true`, `maxDependancyInstallTime:30`, 4 langues, `compatibility`). **Ne PAS toucher.** Dette signalée (non corrigée dans ce cycle) : `link.forum`/`link.video` restent des placeholders texte (« Lien vers le forum ») — acceptable en beta ; à remplacer par de vraies URLs (ou l'URL des issues GitHub) avant une soumission store.
- **AC1** (« MVP aucune dépendance ; post-MVP installe paho-mqtt proprement ») : **satisfait par construction** — le plugin est post-MVP (commandes livrées), donc les 3 deps pip + `hasDependency:true` sont l'état correct. La clause « MVP = vide » est historique.

### Volet B — Documentation utilisateur `docs/fr_FR/index.md` (livrable principal)
Réécriture/extension de la **source FR** (les 3 autres langues = étape 10, translator). Modifications :
1. **Corriger l'erreur factuelle** ligne ~36 : « le plugin ne télécharge ni n'analyse aucun APK » est
   **FAUX depuis UC61** (et présent **verbatim dans les 4 langues** — `fr:36`, `en:36`, `de:37`, `es:36`).
   **Restructurer « Obtenir les identifiants »** en deux méthodes :
   - **Méthode 1 (recommandée, en un clic) : extraction automatique dans Jeedom** — bouton
     **« Extraire automatiquement »** (UC61). Sélectionner marque + pays → confirmer l'avertissement ToS →
     téléchargement (~100 Mo) + extraction → Client ID/Secret pré-remplis. **Nuance factuelle (non
     alarmiste)** : *s'exécute sur la box Jeedom (nécessite le Python 3 déjà utilisé par le démon, et
     télécharge ~100 Mo directement sur la box) ; sur un Raspberry Pi à carte SD ou en cas d'échec,
     préférer la Méthode 2 sur un PC.*
   - **Méthode 2 (repli) : extraction manuelle sur un ordinateur** — la procédure `psa-car-controller`
     **existante** (conservée), + note sur l'assistant graphique (abrégée).
2. **Section dédiée « Avertissement — API non officielle & risques (ToS) »** (renforce/agrège l'encart
   actuel) : API **consommateur reverse-engineered**, peut **cesser de fonctionner sans préavis**, usage
   **aux risques (dont légaux/ToS) de l'utilisateur**, **aucune garantie** (GPL-3), extraction des
   identifiants sous la responsabilité de l'utilisateur.
3. **Nouvelle section « Pilotage à distance — activation OTP »** (ancrée sur l'UI réelle du fieldset
   « Pilotage à distance (activation OTP) ») : prérequis (**compte connecté** d'abord), 3 étapes —
   **« Envoyer le SMS d'activation »** → **« Code reçu par SMS »** → **« Code PIN de l'application »** (4
   chiffres de l'app mobile) → **« Activer le pilotage à distance »** ; **quotas durs Stellantis** (6 codes
   / 24 h, **20 activations SMS / compte à vie**, définitifs) ; **« Renouveler le jeton distant » sans SMS**
   à l'expiration (à privilégier) ; **compte principal (slot 1) uniquement** ; débloque les commandes
   (réveil, charge, préconditionnement, verrouillage, klaxon/feux).
4. **Nouvelle section brève « Comptes secondaires (multi-marques, lecture seule) »** — **3-4 lignes**
   (UC54) : rattacher une 2ᵉ/3ᵉ marque via une section repliable, **même procédure** que « Connexion au
   compte », **lecture seule** (pas de pilotage à distance sur ces comptes).
5. **Remplacer le placeholder « Étapes suivantes »** par :
   - **« Fonctions disponibles »** (concis) : télémétrie (batterie/charge/autonomie/carburant, position,
     km, portes/ouvrants, pression pneus, entretien), **commandes à distance**, **panneau carte « Mes
     véhicules »**, **geofencing/domicile**, **alertes véhicule**, **stats de charge**.
   - **« Limites & bonnes pratiques »** : fraîcheur (**polling ~5 min, pas de push temps réel**), **risque
     batterie 12 V** (wakeup manuel / auto-wakeup opt-in), **anti-ban** (quotas & cooldowns — ne pas forcer
     les rafraîchissements), **mode privacy** (partage de données coupé côté voiture ⇒ le plugin se met en
     veille, ce n'est pas une panne), **pilotage à distance mono-compte**, **API pouvant casser**.

### Volet C — Cohérence UI `plugin_info/configuration.txt` (correction ciblée)
La page de config affiche « à l'aide d'un **outil externe** » **au-dessus** du bouton « Extraire
automatiquement » (UC61) — contradiction sur le même écran. Corriger les chaînes FR concernées pour
mentionner l'extraction automatique comme voie principale et l'outil externe comme repli :
- Alerte `:38` (« … doivent être extraits … à l'aide d'un outil externe. Consultez la documentation… »).
- Tooltips « extrait via un outil externe » : Client ID `:55`, Client Secret `:63` **et** leurs
  équivalents dans les sections comptes secondaires (`:224`, `:232`) — **traiter toutes les occurrences**
  pour rester cohérent.
> ⚠️ **Contrainte process** : `plugin_info/configuration.php` **n'est pas éditable** par les outils
> (permissions) → éditer **uniquement `configuration.txt`**, puis **synchroniser** :
> `cp plugin_info/configuration.txt plugin_info/configuration.php` (copie intégrale, cf. CLAUDE.md).
> Les chaînes modifiées/nouvelles restent **enveloppées `{{...}}`** (FR) ; l'i18n en/de/es est faite en
> **étape 10** (translator) — clés sources changées → clés orphelines à retirer des 3 JSON.

### Volet D — Spec périmée `.memory/specs/post-mvp/80-livraison/82-packaging-doc.md`
Corriger la section **« Détails techniques »** (`:19-22`) : remplacer `paho-mqtt <2.0.0`, `cryptography`,
`jeedomdaemon~=1.2.0` par l'**état réel implémenté** (`paho-mqtt` **1.6.1** pin exact + `requests` +
`pycryptodomex` ; démon = squelette `demond.py` + lib `jeedom/`, **pas** `jeedomdaemon`), avec renvoi à
CLAUDE.md / mémoire `jeedom-packagesjson-shell-pitfall`. **Interne FR, non traduit.**

## Server vs Client
**N/A** (aucune logique exécutable nouvelle). La doc/UI *décrit* des surfaces existantes.

## Validation
- **Éditoriale** : la doc doit refléter les **libellés UI réels** (grep `configuration.txt` pour citer
  exactement « Envoyer le SMS d'activation », « Extraire automatiquement », etc.) et le **comportement
  réel** (quotas OTP, slot 1, ~100 Mo, Python sur la box).
- **Packaging** : confirmer `packages.json`/`info.json` inchangés & corrects.
- **Sync obligatoire** : `configuration.php` = copie exacte de `configuration.txt` après édition.
- **Garde anti-fuite** : aucun secret/token/VIN réel dans les exemples de doc.

## Server Actions / API
**Aucune.** La doc *référence* des actions AJAX existantes (`extractCredentials`, `getAuthUrl`,
`submitAuthCode`, `requestOtpSms`, `activateOtp`, `renewRemoteToken`) sans les modifier.

## Dépendances
**Aucune nouvelle.** `packages.json` inchangé.

## i18n (récapitulatif pour l'étape 10)
- `docs/{en_US,de_DE,es_ES}/index.md` : **répliquer la structure restructurée** de la FR **section par
  section** (pas un patch incrémental — nouvelles sections OTP/comptes secondaires/ToS/Fonctions/Limites +
  réordonnancement « Obtenir les identifiants »). Corriger l'erreur APK dans les 3 langues **ce cycle**.
- `core/i18n/{en_US,de_DE,es_ES}.json` : traduire les chaînes `{{...}}` **modifiées/ajoutées** de
  `configuration.txt` (Volet C) et **retirer les clés orphelines** correspondantes.
- `82-packaging-doc.md` (Volet D) : interne FR, **non traduit**.
