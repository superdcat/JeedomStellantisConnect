# 22 — Programmation de la charge

**Domaine :** Énergie / charge · **Dépend de :** UC14 (commande charge), UC11/12 (démon/OTP) · **Statut :** à spécifier

## Objectif / valeur
Programmer la charge (heure de fin / heures creuses / seuil %) au lieu d'un simple start/stop immédiat —
cas d'usage majeur pour optimiser le coût/énergie depuis Jeedom.

## Périmètre
- **Inclus** : commande(s) action pour définir un horaire de charge et/ou un pourcentage cible
  (publish MQTT), lecture de la programmation courante (info).
- **Exclu** : start/stop immédiat (UC14).

## Détails techniques
- Référence : endpoint local `psa_car_controller` `/charge_control?vin=&hour=&minute=&percentage=` et
  payload de charge programmée `{"program":{hour,minute},"type":…}` ; côté Stellantis officiel,
  `charging.nextDelayedTime` (RFC3339). Adapter au contrat MQTT consommateur.
- Exposer : `charge_set_time` (action paramétrée heure/min), éventuel `charge_set_threshold` (%), info
  `charge_next_time` (déjà en UC21).

## Critères d'acceptation
- [ ] L'utilisateur peut fixer une heure de fin de charge (et/ou seuil %) appliquée par le véhicule.
- [ ] La programmation courante est lisible dans Jeedom (`charge_next_time`).

## À confirmer
- Le contrat exact de la programmation côté **API consommateur** (vs officielle) — payload `programs`
  récurrence, seuil % supporté ou non — cf. `stellantis-implementations-reference.md`.
