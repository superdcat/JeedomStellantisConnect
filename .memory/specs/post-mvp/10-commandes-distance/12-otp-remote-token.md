# 12 — Activation OTP & remote token

**Domaine :** Commandes à distance · **Dépend de :** UC11 (démon), MVP/03 (OAuth2) · **Statut :** à spécifier

## Objectif / valeur
Obtenir le **remote token** indispensable aux commandes MQTT. Il est **distinct** du token OAuth2 REST et
s'obtient par une **activation OTP** unique (SMS + **PIN 4 chiffres de l'app mobile**). Sans lui, aucune
commande à distance n'est possible.

## Périmètre
- **Inclus** : UI de setup OTP (déclencher l'envoi du SMS, saisir SMS+PIN), obtention &
  **stockage chiffré** du `remote_token`/`remote_refresh_token`, refresh du remote token, détection
  d'expiration → **alerte « refaire la procédure OTP »**.
- **Exclu** : l'usage du remote token par les commandes (UC13-17).

## Détails techniques
- Procédure (cf. `psa_car_controller` FAQ / `RemoteClient`) : serveur OTP `https://otp.mpsa.com`,
  l'utilisateur reçoit un SMS, fournit le code SMS + le **PIN 4 chiffres** de l'app → génère un objet OTP
  (équivalent `otp.bin`) → `remote_token` + `remote_refresh_token`.
- ⚠️ **Quotas durs** : **6 générations OTP / 24 h**, et **20 activations SMS / compte** au total →
  **ne JAMAIS** re-tenter en boucle (un compte peut se **bloquer**). À l'expiration : **alerter**
  l'utilisateur (log + page Santé + message) plutôt que de régénérer silencieusement.
- Stockage : `remote_token`/`remote_refresh_token` chiffrés (cache/config), **séparés** du token OAuth2.
- Refresh : tenter `remote_refresh_token` ; si échec (`Can't refresh remote token`) → état
  `otp_required` (ré-activation manuelle nécessaire).

## Critères d'acceptation
- [ ] L'utilisateur peut réaliser l'activation OTP depuis la page de config (SMS + PIN) une fois.
- [ ] Le `remote_token` est stocké chiffré et réutilisé par le démon (UC11) pour les commandes.
- [ ] À expiration, le plugin **alerte** sans re-tenter en boucle (respect des quotas 6/24 h, 20 SMS).
- [ ] Aucun token/PIN en clair dans les logs.

## À confirmer
- Endpoints/format exacts de l'activation OTP et du refresh remote token (issues `psa_car_controller`
  #851/#925/#967) — cf. `.memory/analyse/stellantis-implementations-reference.md`.
- Faisabilité de la procédure OTP **côté démon** vs côté PHP (le PIN vient de l'app mobile utilisateur).
