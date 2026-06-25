# 43 — Alertes véhicule (AdBlue, lave-glace, voyants, révision)

**Domaine :** Entretien / alertes · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Remonter les alertes/voyants exposés par le véhicule (niveau AdBlue, lave-glace, alertes service, voyants
divers) pour notifier l'utilisateur via Jeedom.

## Périmètre
- **Inclus** : commandes info pour les alertes présentes dans `/status`, normalisées (binary actif/inactif
  + libellé), info agrégée `alerts_count`.
- **Exclu** : interprétation médicale fine de chaque code constructeur (best effort + libellé brut).

## Détails techniques
- Source : **`GET /user/vehicles/{id}/alerts`** (confirmé) → `alerts[].{id, type (AlertMsgEnum), active,
  started_at, end_at}`. **AlertMsgEnum ~80 types** (moteur, carburant/AdBlue, pneus, freinage, ouvrants,
  éclairage — cf. `[[stellantis-data-model]]` § 3). Mapping défensif : une commande binaire par type
  rencontré, libellé brut conservé.
- Info `alerts_count` (numeric) pour un widget de synthèse + scénario « au moins une alerte ».

## Critères d'acceptation
- [ ] Les alertes présentes remontent en commandes info exploitables (binary + libellé).
- [ ] Une info agrégée permet un scénario « le véhicule a une alerte ».

## À confirmer
- Catalogue réel des alertes exposées côté consommateur (variable, à recouper avec HA integration).
