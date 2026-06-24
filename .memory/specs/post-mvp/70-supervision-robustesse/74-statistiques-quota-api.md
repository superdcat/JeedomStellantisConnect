# UC — Statistiques d'utilisation & quota d'appels API

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/02 (client HTTP) · **Complémentaire de :** UC72 (rate-limiting), UC73 (sync sélective) · **Fournit la donnée à :** UC77 (régulation de la cadence de refresh) · **Statut :** à spécifier (tech)

## Objectif / valeur
Compter de façon fiable **tous** les appels à l'**IMOU Open API** (centralisés dans la brique unique
`imouApi`), par fenêtre **mois** (prioritaire) et **jour**, et **par endpoint**, pour :
- **surveiller la consommation** vis-à-vis du **quota mensuel du compte** et **alerter** avant de l'atteindre ;
- fournir la **donnée chiffrée** sur laquelle s'appuient UC72 (backoff) et surtout **UC77** (calcul
  automatique de la cadence de rafraîchissement pour ne pas épuiser le quota).

## ⚠️ Contrainte réelle — quota MENSUEL global (écart doc / terrain)
La page doc `faq/limit.html` ne documente que des **plafonds JOURNALIERS PAR INTERFACE** (ex.
`accessToken` 2 000/j, `deviceOnline` 100 000/j, `controlPTZ` 200 000/j, `setDeviceSnap` 100 000/j…),
**sans total mensuel ni distinction gratuit/payant**. **Mais** la console développeur IMOU expose un
**budget MENSUEL GLOBAL du compte** : en palier **gratuit ≈ 30 000 appels/mois** (toutes interfaces
confondues). C'est **ce total mensuel qui est le *binding constraint* réel** pour ce plugin — bien
en-dessous des plafonds journaliers par interface, qui ne sont quasiment jamais atteints en polling.

> **Preuve terrain (2026-06)** : ~29 000 appels consommés en **6 jours** avec **11 caméras** en
> simple rafraîchissement auto → la cadence fixe de 5 min (cron5) **épuise le quota mensuel en quelques
> jours**. D'où UC77.

Les deux limites coexistent (hard journalières *par interface* + budget mensuel *global du compte*) :
cette UC **surveille en priorité le total mensuel**, configurable (un abonnement/quota entreprise le
relèverait).

## Périmètre
- **Inclus** :
  - **Comptage** de tous les appels — **mois courant** + **jour courant**, **total** + **par endpoint** —
    instrumenté dans `imouApi` (point unique par lequel passe tout appel HTTP).
  - **Configuration plugin** :
    - **Quota mensuel total** d'appels (défaut **30 000**, éditable si abonnement/quota supérieur) ;
    - **Jour de reset du quota** (1–31) : le quota se réinitialise à la **date anniversaire
      d'inscription** du compte, **pas** le 1er calendaire. L'utilisateur trouve le jour dans son compte
      IMOU (champ **`Register Time`**, ex. `2025-06-02 08:41:17` → jour de reset = **2**). Défaut **1** ;
    - **Part du quota réservée au rafraîchissement automatique** (défaut **70 %**, éditable) — consommée
      par UC77 ;
    - (optionnel) **seuil d'alerte** en % du quota mensuel (défaut **90 %**).
  - **Restitution** : consommation du **mois** (total + **% du quota**), du **jour**, et **détail par
    endpoint** — sur la **page de configuration plugin** et la **page Santé** Jeedom.
  - **Alerte** (log/message) à l'**approche du quota mensuel** (≥ seuil d'alerte) ; journalisation du
    dépassement du budget refresh.
- **Exclu** :
  - La **régulation automatique de la cadence** elle-même → **UC77** (consomme cette donnée).
  - La **consommation de DATA du flux live** (≈ **3 Go/mois** en gratuit) : métrique distincte (octets,
    pas appels), liée au live (UC22/UC25) — **hors scope** de cette UC → traitée par **UC78** (estimation
    informative, réutilise la machinerie de période / le jour de reset définis ici).
  - La facturation.

## Esquisse Jeedom
- **Compteur** : incrémenté dans `imouApi` (au niveau `callOnce`, **après réception d'une réponse
  serveur** → toutes les requêtes *reçues par l'API* comptent, **rejeux UC72 inclus** ; un échec
  transport sans réponse n'est pas compté car il ne consomme pas le quota). Persisté via la classe
  `cache` Jeedom :
  - clé **période** `imou::stats::period::<débutPériode>` → `{ total, byMethod, alerted }` (autoritatif
    pour le quota), où `<débutPériode>` = date `AAAA-MM-JJ` du **début de la période de facturation
    courante**, dérivée du **jour de reset** (cf. ci-dessous) ;
  - clé **journalière** `imou::stats::day::AAAA-MM-JJ` → `{ total, byMethod }` (détail récent, TTL court ~8 j) ;
  - préfixes `period::` / `day::` disjoints (pas de collision de clés).
  - Instrumentation **NON BLOQUANTE** : `try/catch Throwable`, **jamais** d'exception remontée à l'appel
    métier (critère dur).
- **Fenêtre mensuelle (anniversaire)** : la période courante va du **jour de reset** de ce mois (ou du
  mois précédent si la date du jour est *avant* le jour de reset) jusqu'au jour de reset suivant.
  **Clamp** si `quotaResetDay` (ex. 31) dépasse le nombre de jours du mois → dernier jour du mois. Une
  méthode utilitaire `imou::currentQuotaPeriodStart()` calcule `<débutPériode>` à partir de `quotaResetDay`.
- **Config** : `quotaMensuel` (défaut 30000), `quotaResetDay` (1–31, défaut 1), `quotaRefreshPct`
  (défaut 70), `seuilAlertePct` (défaut 90), au **niveau plugin** (`config::save/byKey(..., 'imou')`).
  Lecture du seuil/jour de reset mise en **cache statique PHP** (pas de lecture DB à chaque appel).
- **Restitution** : une méthode de lecture (ex. `imou::getApiStats()`) agrège mois/jour/endpoint pour
  l'affichage ; ligne(s) dédiée(s) dans `imou::health()`.
- **Alerte** : à l'approche du quota mensuel (total mois ≥ `seuilAlertePct` % de `quotaMensuel`) → log
  `warning` **débouncé 1×/période** (flag `alerted` dans le blob de période).
- **Limite assumée** : une **purge manuelle du cache Jeedom** (Administration) remet les compteurs à zéro
  → indicateur **best-effort**, documenté (acceptable pour de la supervision, pas de la facturation).

## Critères d'acceptation
- [ ] Tous les appels passent par `imouApi` et sont comptés (mois + jour, total + par endpoint).
- [ ] L'utilisateur consulte la consommation du **mois** (total + % du quota) et du **jour**.
- [ ] `quotaMensuel` (défaut 30 000), `quotaResetDay` (jour anniversaire, défaut 1) et `quotaRefreshPct`
      (défaut 70 %) sont **configurables** ; le compteur de période suit le **jour de reset** choisi.
- [ ] Une **alerte** (log/message) se déclenche à l'approche du quota mensuel.
- [ ] Le comptage **ne casse jamais** un appel métier (instrumentation non bloquante, jamais d'exception).

## À confirmer
- **Valeur exacte** du quota mensuel gratuit (30 000 observé en console — à confirmer/calibrer ; mis par
  **défaut**, éditable).
- **Reset mensuel** : ✅ **décidé** — fenêtre **anniversaire**, jour configurable (`quotaResetDay`, lu par
  l'utilisateur dans `Register Time`). Reste à confirmer que le reset console IMOU est bien
  *anniversaire d'inscription* (et non glissant 30 j) ; le jour étant éditable, l'erreur est rattrapable.
- **Portée du quota** : global au compte vs par app/clé (impacte un éventuel multi-instances).
- Granularité de rétention du détail journalier.
