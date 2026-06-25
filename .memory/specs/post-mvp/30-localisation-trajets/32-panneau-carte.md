# 32 — Panneau carte « Mes véhicules »

**Domaine :** Localisation / trajets · **Dépend de :** UC31 (position) · **Réf. archi :** `jeedom-panel-page-menu.md`, `jeedom-widgets-commandes.md` § 7 · **Statut :** à spécifier

## Objectif / valeur
Une **page-panneau** au menu Jeedom affichant les véhicules sur une **carte** (et un widget de tuile carte
sur dashboard), pour visualiser d'un coup d'œil où ils sont.

## Périmètre
- **Inclus** : page panel `desktop/php/panel.php` (déclarée `info.json "display"`), widget carte
  `cmd.info.string.stellantisMap`, endpoint proxy `core/ajax/stellantisMap.ajax.php`.
- **Exclu** : historique de trajets (UC33), zones (UC34).

## Détails techniques
- ⚠️ **CSP Jeedom** : une tuile de carte **externe** (`<img src=osm/mapbox…>`) est **bloquée** par le
  navigateur → il faut un **proxy same-origin** : `core/ajax/stellantisMap.ajax.php?eqLogic_id=#…`
  récupère côté serveur une tuile statique centrée sur la position et la relaie (`isConnect()` +
  `hasRight('r')`, cache court). Cf. `jeedom-widgets-commandes.md` § 7.
- **Alternative sans réseau externe** : afficher coordonnées + lien `geo:` cliquable (pas de tuile, pas de
  dépendance). Décider selon le besoin visuel.
- Page panel : toggle **natif** `displayDesktopPanel/Mobile` (rien à coder), sélection par véhicule
  `isVisiblePanel`, accès non-admin `isConnect()` (cf. `jeedom-panel-page-menu.md`).

## Critères d'acceptation
- [ ] Une entrée de menu « Mes véhicules » s'affiche quand l'option panneau est cochée (toggle natif).
- [ ] La position de chaque véhicule est visible (tuile carte via proxy, ou lien carte).
- [ ] Aucun blocage CSP (contenu servi en same-origin).

## À confirmer
- Source de tuiles statiques sans clé/limite (OSM static, etc.) et conditions d'usage.
