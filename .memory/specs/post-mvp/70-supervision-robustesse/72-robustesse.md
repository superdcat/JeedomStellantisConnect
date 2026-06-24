# 16 — Robustesse : rate limiting, retries, logs, i18n

**Phase :** Post-MVP · **Dépend de :** 02 · **Fichiers :** `core/class/imou.class.php`, fichiers de traduction

## Objectif
Fiabiliser le plugin face aux limites de l'API, aux erreurs transitoires et à l'internationalisation.

## Périmètre
- **Inclus** : throttling/backoff, retries ciblés, mapping des codes d'erreur en messages clairs,
  logs propres, fichiers i18n.
- **Exclu** : fonctionnalités métier.

## Détails techniques
- **Rate limiting** : limiter la fréquence d'appels (file/temporisation), respecter la limite
  des 5 appareils, espacer le polling si beaucoup de caméras.
- **Retries** : rejouer les erreurs transitoires (réseau, `SN1005` après resync horloge, token)
  avec backoff borné ; ne pas rejouer les erreurs fonctionnelles.
- **Codes d'erreur** : table `code IMOU → message FR` ; afficher des messages actionnables.
- **Logs** : niveaux cohérents, jamais de secret/token ; un identifiant de corrélation par appel utile.
- **Redirection datacenter** : la réponse `accessToken` peut contenir un champ **`currentDomain`** ;
  l'implémentation officielle **bascule alors son URL de base** sur ce domaine. Aujourd'hui Jeedom fixe le
  host par datacenter (europe/asia/america) et **ignore `currentDomain`** → risque si IMOU redirige un
  compte vers un autre POP. À écouter dans `imouApi` après `getToken`. Cf.
  `.memory/analyse/imou-home-assistant-comparaison.md` (§4, §5.9).
- **i18n** : générer les fichiers de langue (au moins `fr_FR`, `en_US`).

## Critères d'acceptation
- [ ] Une coupure réseau ponctuelle est absorbée sans intervention.
- [ ] Les messages d'erreur affichés sont compréhensibles et traduits.
- [ ] Aucun secret/token dans les logs, quel que soit le niveau.

## Notes / risques
- Les valeurs exactes de quota IMOU n'étant pas publiques, calibrer empiriquement et documenter.
