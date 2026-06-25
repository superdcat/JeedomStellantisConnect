# 74 — Renouvellement & alertes de token

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/03 (OAuth), UC12 (remote token) · **Statut :** à spécifier

## Objectif / valeur
Gérer proprement la **fin de vie** des tokens (OAuth2 et remote token OTP) : refresh automatique quand
possible, **alerte claire** quand une **ré-authentification manuelle** est nécessaire (sans tenter en
boucle, surtout pour l'OTP plafonné).

## Périmètre
- **Inclus** : refresh proactif OAuth2 (déjà MVP/03), détection `invalid_grant` (refresh token mort) →
  alerte « se reconnecter » ; détection remote token expiré → alerte « refaire l'OTP » ; surfaçage des
  états sur page plugin + Santé + message Jeedom.
- **Exclu** : la procédure OTP elle-même (UC12).

## Détails techniques
- OAuth2 : refresh auto ; `invalid_grant` → état `auth_required`, **stop des appels**, alerte (log
  `warning` + message + Santé). Ne pas spammer l'endpoint token.
- Remote token : à l'échec de refresh (`Can't refresh remote token`) → état `otp_required`, alerte.
  ⚠️ **Ne jamais** régénérer l'OTP automatiquement (quota 6/24 h, 20 SMS/compte — risque blocage compte).
- Idéalement : notification Jeedom (message center) à l'utilisateur pour qu'il agisse.

## Critères d'acceptation
- [ ] Un refresh token mort déclenche une alerte « reconnexion requise » sans boucle d'appels.
- [ ] Un remote token expiré déclenche une alerte « refaire l'OTP » sans régénération automatique.
- [ ] Les états sont visibles (page plugin + Santé) ; aucun secret en clair.

## À confirmer
- Durées de vie réelles (access ~890 s ; refresh & remote token : à observer) — calibrer les marges.
