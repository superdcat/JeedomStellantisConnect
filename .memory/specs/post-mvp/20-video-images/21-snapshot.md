# 14 — Snapshot / image widget

> ## ❌ UC RETIRÉE (2026-06-22)
> Décision utilisateur : **on retire UC21** plutôt que de la finaliser. Motifs :
> - **Affichage dashboard impossible en direct** : la CSP Jeedom (`default-src 'self' …`, sans
>   `img-src`) bloque tout `<img>` pointant l'URL OSS externe du cliché (même contrainte qui a
>   imposé le pivot « live-snapshot » d'UC25, cf. `25-widgets-affichage-tech.md`). Un affichage
>   inline ne serait possible qu'au prix d'un **proxy same-origin** dédié (endpoint relayant le JPEG),
>   jugé **trop lourd et redondant** avec la tuile live déjà livrée (UC25/26).
> - **Redondance fonctionnelle** : le widget live (HLS → frames JPEG same-origin) couvre déjà le
>   besoin « voir la caméra » sur le dashboard.
>
> **Conséquences code (commit de retrait)** : suppression de `imou::createSnapshotCommands()`, de la
> branche `snapshot_` de `imouCmd::execute()` et de `imouCmd::actionSnapshotGet()` ; retrait des clés
> i18n « Capturer une image » / « URL du cliché ». (Pas de purge des commandes `snapshot_get`/
> `snapshot_url` éventuellement créées sur d'anciennes installs de dev : choix assumé — non livré, et
> Jeedom permet de les retirer à la main si besoin.) **Conservé** : `imou::captureSnapshot()` et
> `imou::sanitizeImageUrl()` — toujours utilisés
> par **UC55** (miniature, source « cliché live »). La capture d'un cliché reste donc accessible via
> la miniature d'équipement (UC55), pas comme commande de dashboard.
>
> Le contenu ci-dessous est l'**énoncé d'origine**, conservé pour mémoire.

---

## Objectif
Récupérer une image instantanée de la caméra et l'exposer dans Jeedom.

## Périmètre
- **Inclus** : commande action « Capturer » + commande info image/URL ; affichage widget.
- **Exclu** : flux vidéo continu (tâche 15).

## Détails techniques
- `setDeviceSnap(deviceId, channelId)` → renvoie une URL d'image (souvent temporaire/signée).
- Commande info `snapshot` (subType `string`) stockant l'URL, ou téléchargement local dans
  `data/` du plugin si l'URL expire vite.
- Affichage : widget image, ou bouton ouvrant l'URL.

## Critères d'acceptation
- [ ] La commande « Capturer » renvoie une image récente et affichable.
- [ ] Gestion propre de l'expiration de l'URL (re-capture).

## Notes / risques
- Vérifier la durée de validité de l'URL renvoyée ; prévoir un proxy/cache local si nécessaire.
