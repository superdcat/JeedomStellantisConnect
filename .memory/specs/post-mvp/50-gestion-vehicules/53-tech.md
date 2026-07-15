# Spec technique — 53 (Multi-véhicules & multi-comptes)

> ⚠️ **UC de CADRAGE — livrable 100 % documentaire, AUCUN code.** Décision prise avec l'utilisateur
> (2026-07-15, workflow `/feature 53`, après challenge advisor) : UC53 **ne modifie aucun fichier
> source**. Elle (1) **valide et documente** la couverture d'AC1 (« sync correcte/performante à
> plusieurs véhicules », déjà acquis au MVP), et (2) **cadre et tranche** le besoin multi-comptes (AC2),
> dont l'**implémentation** est renvoyée à **UC54**. Le hardening anti-rafale envisagé au départ (spread
> des refresh cron) a été **déplacé en UC72** (sa vraie place thématique : anti-ban). Voir « Périmètre &
> décisions » ci-dessous.

## Architecture

**Aucun composant runtime, aucune classe, aucun endpoint, aucune commande, aucune dépendance.** Le
livrable est un ensemble de **documents** :

| Fichier | Nature | Rôle dans UC53 |
|---|---|---|
| `.memory/specs/post-mvp/50-gestion-vehicules/53-tech.md` | spec technique (ce fichier) | Décision + couverture AC1/AC2 |
| `.memory/analyse/stellantis-api-architecture.md` (§ 4) | analyse versionnée | Décision d'archi multi-comptes capitalisée (+ correction de la sur-affirmation § 4.4) |
| `.memory/specs/post-mvp/50-gestion-vehicules/54-multi-marques.md` | spec fonctionnelle UC54 | Reciblée sur un **namespacing par compte** (voir « Écart assumé ») |
| `.memory/specs/post-mvp/70-supervision-robustesse/72-rate-limiting-anti-ban.md` | spec fonctionnelle UC72 | Accueille le **spread anti-rafale du polling** (déplacé) |
| `.memory/analyse/INDEX.md` | index des analyses | Ligne § 0 + date de synchro |

## Server vs Client

Sans objet (aucun composant exécuté). Rappel de la contrainte structurante utilisée par le cadrage :
**un plugin Jeedom = une instance = un espace de configuration** (`config::byKey(..., 'stellantis')`),
donc aujourd'hui **un seul jeu** `brand`/`client_id`/`client_secret`/`country`/`redirect_uri`, **une**
clé de cache token globale (`stellantisApi::TOKEN_CACHE_KEY`) et **un** remote token OTP. Conséquence
directe : **1 installation = 1 compte = 1 marque**.

## Validation

Sans objet (pas de saisie utilisateur, pas de logique côté client/serveur introduite).

## Couverture AC1 — « la sync reste correcte et performante avec plusieurs véhicules »

Cadrage explicite en trois strates (⚠️ « sync » recouvre **deux** chemins distincts : le clic
*Synchroniser* → `syncVehicles()`, et le rafraîchissement périodique → `cron()`).

### (a) Déjà acquis au MVP — AC1 fonctionnellement satisfait
- **Multi-véhicules d'un compte = nominal.** `discoverVehicles()` (`core/class/stellantis.class.php:391`)
  itère `_embedded.vehicles` ; `syncVehicles()` (`:484`) est **idempotent** (clé `logicalId = VIN`,
  `try/catch` par véhicule, cooldown serveur 15 s anti double-clic/rejeu, désactivation — non suppression —
  des véhicules disparus).
- **Mutualisation du token au cron.** `cron()` (`:3566`) **prime le token une seule fois par passe**
  (`stellantisApi::getToken()` en tête) : si le refresh OAuth casse, aucun véhicule ne retente son propre
  refresh (protège le quota `REFRESH_QUOTA`). Isolation `try/catch` **par véhicule** (un véhicule en erreur
  n'interrompt pas la boucle).
- **Quotas durs = GLOBAUX au compte, pas par véhicule.** `WAKEUP_QUOTA_KEY` (`:2274`) et le quota de
  refresh token (`REFRESH_QUOTA`) sont des **clés de cache globales** : ils ne se dégradent donc **pas**
  avec le nombre de véhicules. C'est la garantie centrale d'AC1 côté anti-ban.
- **Backoff 429 réactif global.** `stellantisApi::rateLimitRemaining()` fait **sauter toute la passe** cron
  (et court-circuite `callWithToken()`) tant qu'un cooldown est actif — une rafale qui déclencherait un 429
  est absorbée pour l'ensemble de la flotte.

### (b) Limites connues, NON traitées ici (documentées, jugées négligeables à l'échelle foyer 1-4)
- **`syncVehicles()` est synchrone dans un seul appel AJAX** : pour N véhicules, il enchaîne
  `save` + `assurerImageVehicule` + `createCommands` + `refreshTelemetry` (≥ 2 appels REST/véhicule,
  + endpoints throttlés) **séquentiellement**. Latence proportionnelle à N. Acceptable pour un foyer
  (1-4 véhicules), déclenché manuellement et rarement, borné par le cooldown 15 s. **Pas d'asynchronisation
  prévue** (surdimensionné pour la cible).
- **Pagination `/user/vehicles` non gérée** : `discoverVehicles()` (`:433-437`) ne suit pas `_links.next`
  mais **le loggue en warning** depuis UC05. Improbable pour un foyer. Connu, non traité, risque jugé
  négligeable à cette échelle.

### (c) Renvoyé à UC72 — anti-rafale proactif du polling (déplacé, hors UC53)
À la cadence par défaut `CRON_DEFAUT = '*/5 * * * *'`, **tous** les véhicules tombent dus à la **même
minute** (0, 5, 10…) → rafale de N × (~2 appels REST) sur une minute. Aujourd'hui absorbée **seulement
réactivement** (backoff 429). Un **spread proactif** (décalage de phase déterministe pour les véhicules en
cadence par défaut, ex. offset `eqId % 5` → expression `'%d-59/5 * * * *'`, `autorefresh` personnalisé
respecté tel quel) lisserait la charge. **Décision : hors UC53** (ROI théorique pour 1-4 véhicules ;
aucun rate-limit spécifique `/status` documenté au-delà du 429 générique déjà couvert ; syntaxe cron non
éprouvée dans ce projet → vérif recette requise). **Rangé sous UC72** (« aucun chemin de code ne peut
émettre des appels en rafale — cron, commandes, wakeup »), avec ses limites à assumer le jour où on
l'implémente : collision résiduelle mod-5 (les `eqId` sont globaux, non denses) et **changement de phase
observable** (`last_update` passerait de :00/:05 à :01/:06…, à signaler au changelog utilisateur).

## Décision AC2 — cadrage multi-comptes (implémentation = UC54)

### Contrainte
1 plugin Jeedom = 1 config = 1 token global = 1 OTP → **aujourd'hui : 1 compte / 1 marque**. La table
`BRANDS` (TLD/realm par marque) rend la marque **sélectionnable**, **pas simultanée** — un foyer
Peugeot **+** Citroën n'est **pas** supporté en l'état. ⚠️ Cela **corrige** la sur-affirmation historique
de `stellantis-api-architecture.md` § 4.4 (« multi-marques dès le MVP, seul impact la table TLD/realm ») :
la table est nécessaire mais **non suffisante** (il manque le namespacing des credentials/tokens).

### Décision de design pour UC54 — namespacing par **identifiant de compte générique**
Généraliser la config mono-compte en une **table indexée par un identifiant de compte** (slug/index
arbitraire), la **marque devenant un simple attribut du compte** :

```
accounts: {
  <accountId>: { brand, client_id, client_secret, country, redirect_uri }
}
```

- Clé de cache token → `TOKEN_CACHE_KEY . '::' . <accountId>` ; remote token OTP idem, **par compte**.
- `stellantisApi` résout le compte (donc marque + credentials + realm + TLD) depuis la config du véhicule
  traité (`eqLogic->getConfiguration('accountId')`, la `brand` restant portée par le véhicule pour le
  routage TLD/realm).
- **Cron** : primer le token **une fois par compte distinct** présent dans la flotte (généralise la
  mutualisation actuelle « 1×/passe » en « 1×/compte/passe »).
- Setup OAuth : **une fois par compte** utilisé.

### Résolution du « À confirmer » (2 comptes de la MÊME marque)
Le refus de ce cas dans une première lecture était **circulaire** : il découlait du choix de clé
`brand`, pas d'une contrainte technique. En namespaçant par **compte** (et non par marque), le cas « 2
comptes Peugeot » devient **structurellement possible au même coût d'implémentation** que le
multi-marques. → **Décision** : ce n'est **pas** un objectif de lancement pour UC54 (cas rare), mais le
design account-keyed **ne le ferme pas** — l'exposer ou non reste un **choix produit/ergonomie
diffé­rable à UC54**, sans dette de redesign. On ne grave donc **aucune** impossibilité technique.

### Écart assumé vs `54-multi-marques.md`
La spec UC54 actuelle propose un namespacing **par `brand`** (`{brand → {client_id…}}`). Ce cadrage UC53
la **recible sur un namespacing par compte** (plus général, future-proof, coût identique). `54-…md` est
mise à jour en conséquence (périmètre, détails techniques, critères, « à confirmer »).

## Server Actions / API

Aucune. (Rappel : tout appel REST resterait derrière `stellantisApi` ; UC54 devra veiller à ne pas
introduire de point d'entrée externe appelant une classe hors `stellantis`/`stellantisApi` — règle
d'autoload Jeedom.)

## Dépendances

Aucune (ni PHP, ni pip, ni fichier de package).

## i18n

Aucune chaîne UI introduite (livrables = analyse/specs internes en français). Rien à traduire ; les
`core/i18n/*.json` ne sont pas touchés.

## Critères d'acceptation — état
- [x] **AC1** — sync correcte/performante à plusieurs véhicules : **satisfait au MVP**, désormais
  **documenté explicitement** (acquis en (a) ; limites assumées en (b) ; amélioration proactive rangée en
  UC72 en (c)).
- [x] **AC2** — besoin multi-comptes cadré : **décision documentée** (namespacing par compte, résolution
  du « à confirmer », implémentation renvoyée à UC54 ; capitalisée dans l'analyse d'architecture).
