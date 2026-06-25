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
- Champs d'alertes/voyants (data-model § 2.6) : structure variable → mapping défensif (créer une commande
  par type rencontré, libellé brut conservé).
- Info `alerts_count` (numeric) pour un widget de synthèse + scénario « au moins une alerte ».

## Critères d'acceptation
- [ ] Les alertes présentes remontent en commandes info exploitables (binary + libellé).
- [ ] Une info agrégée permet un scénario « le véhicule a une alerte ».

## À confirmer
- Catalogue réel des alertes exposées côté consommateur (variable, à recouper avec HA integration).
