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

## Réutilisation UC42 (⚠️ ne pas dupliquer le poller `/alerts`)
UC42 (pression pneus) a déjà livré, **avant** ce socle, un lecteur `/alerts` autonome et générique dans
`core/class/stellantis.class.php` (cf. `42-tech.md`) :
- `parseAlertes()` (pur) renvoie déjà la **liste générique des types d'alerte actifs** (pas seulement
  pneus) — à réutiliser tel quel pour le catalogue complet.
- `suivreAlertes()` porte le fetch + throttle + cache (`ALERTS_NEXT_KEY`, TTL 1 h/7 j/3 h).
  **UC43 doit brancher ses commandes (une binaire par type + `alerts_count`) DANS cette méthode**, en
  réutilisant le `$typesActifs` déjà parsé — **jamais** créer un second appel/cache sur `/alerts` (sinon
  deux pollers concurrents → budget anti-ban doublé, TTL qui se marchent dessus).
- La sémantique **fail-closed** de `active` (absente ⇒ inactive) et la comparaison insensible à la casse
  sont déjà en place et doivent rester.

## À confirmer
- Catalogue réel des alertes exposées côté consommateur (variable, à recouper avec HA integration).
