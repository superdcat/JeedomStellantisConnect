# 07 — Commandes info de télémétrie

**Phase :** MVP · **Dépend de :** 06 · **Fichiers :** `core/class/stellantis.class.php`

## Objectif
Doter chaque véhicule de ses **commandes info** Jeedom correspondant à la télémétrie lue via
`GET /user/vehicles/{id}/status` (+ `/lastPosition`) : batterie/SOC, autonomie, charge, carburant,
kilométrage, position, état portes/verrouillage, fraîcheur.

## Périmètre
- **Inclus** : création **idempotente** des commandes info (clé = `logicalId`) ; parsing du statut →
  valeurs ; création **conditionnelle selon la motorisation / présence du champ**.
- **Exclu** : la boucle de rafraîchissement (→ 08) ; les commandes **action** (post-MVP, MQTT).

## Détails techniques
- Hook : créer les commandes dans `stellantis::postSave()` (ou depuis la sync) via `createCommands()`
  idempotente. Socle MVP par véhicule (créer si la donnée existe dans `/status`) :
  | logicalId | name | type | subType | generic_type |
  |---|---|---|---|---|
  | `battery_soc` | Batterie | info | numeric | `BATTERY` |
  | `autonomy` | Autonomie | info | numeric | — |
  | `charging_status` | État de charge | info | string | — |
  | `charging_plugged` | Câble branché | info | binary | `PRESENCE` |
  | `fuel_level` | Carburant | info | numeric | — |
  | `mileage` | Kilométrage | info | numeric | — |
  | `doors_locked` | Verrouillage | info | binary | — |
  | `position` | Position | info | string | `GEOLOC` (format `lat,lon`) |
  | `last_update` | Dernière MAJ | info | string | — |
- Parsing : méthode `stellantis::parseStatus(array $status): array` (logicalId→valeur) qui isole le
  mapping des champs API (cf. `.memory/analyse/stellantis-data-model.md`) du reste du code. Mise à jour
  via `checkAndUpdateCmd($logicalId, $val)`.
- **Création conditionnelle** : pas de `battery_soc`/`autonomy` élec sur thermique pur ; pas de
  `fuel_level` sur VE → se baser sur `energies[].type` et la **présence effective** du champ.
- `generic_type` posés pour des widgets propres ; `position` en `string "lat,lon"` (widget carte = UC32).

## Critères d'acceptation
- [ ] Après synchro, chaque véhicule possède les commandes info correspondant à sa motorisation.
- [ ] Une re-synchro ne duplique pas les commandes.
- [ ] Les valeurs reflètent le `/status` réel (SOC, autonomie, km, verrouillage, position).

## Notes / risques
- Noms de champs : voir `[[stellantis-data-model]]` (chemins confirmés sur dumps réels). **Pièges
  connus** : objet `preconditionning` (à **double n** dans l'API réelle), position GeoJSON `[lon,lat]`
  (**pas** `[lat,lon]`), **ne pas** envoyer `?extension=odometer/kinetic` (HTTP 400 depuis v4.15 ; champs
  à la racine), lire `energies[]` (v4.15+) avec fallback `energy[]`. Isoler tout le mapping dans
  `parseStatus()` pour le corriger sans toucher au reste.
- Ne créer que ce qui est présent : éviter des commandes « toujours vides » qui polluent l'UI.
