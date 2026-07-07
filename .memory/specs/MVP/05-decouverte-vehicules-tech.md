# Spec technique — MVP 05 — Découverte des véhicules

> Référence fonctionnelle : `05-decouverte-vehicules.md`. Dépend de MVP 03 (`callWithToken`).
> Contrat vérifié le **2026-07-06** contre `psa_car_controller`
> (`models/vehicle.py`, `models/vehicle_engine.py`) + analyse `stellantis-data-model.md` § 1.

## Contrat API (vérifié)
- `GET /user/vehicles` → HAL `{_embedded: {vehicles: [...]}, _links: {...}}`.
- Chaque véhicule : `{id, vin, brand, label, engine: [{class, energy}], pictures, createdAt, _links}`.
  - `id` = id API (≠ VIN, requis pour `/status` etc.) ; `vin` = identité stable (`logicalId` UC06).
  - `label` = « version » du véhicule — en pratique **surnom renommable** par l'utilisateur dans
    l'app mobile (pré-rempli avec la désignation commerciale). **Aucun champ `model` ni
    `motorization` dans l'API** (écart acté : `model` retiré du contrat de sortie, specs 05/06 mises
    à jour le 2026-07-06).
  - `engine[].class` ∈ `{Thermic, Electric}` (enum du modèle généré) ; `engine[].energy`
    ∈ `{GPL, Gasoil, Petrol, Biologic}` (absent sur Electric).

## Architecture — `core/class/stellantis.class.php` uniquement, classe `stellantis`
- `public static function discoverVehicles(): array` — liste de
  `['id' => string, 'vin' => string, 'brand' => string, 'label' => string, 'energy' => string]`.
  - 1 appel `stellantisApi::callWithToken('GET', '/user/vehicles')` — **pas de boucle de
    pagination** (advisor 2026-06-06 : le code de référence ne pagine jamais, volume structurellement
    faible, chemin intestable qui risquerait de perdre la page 1 sur un bug de page 2). Si
    `_links.next.href` est présent → `log::add warning` « pagination détectée, non gérée » (sans
    l'URL — elle peut porter des query sensibles) et retour des véhicules déjà obtenus. À
    implémenter réellement si ce warning est un jour observé.
  - Entrée sans `id` ou `vin` → ignorée + warning avec l'`id` API si présent (jamais le VIN).
  - Compte sans véhicule / réponse sans `_embedded.vehicles` → `[]` (pas d'erreur).
    - ✅ **Confirmé en prod (2026-07-07)** : l'API ne renvoie **pas** un `200` avec `_embedded.vehicles`
      vide, mais un **`404`** avec un corps `{"code":40400,"message":"No vehicle found",...}`. Détecté
      par `stellantisException::typeFromResponse()` (`httpCode == 404 && body.code == 40400` →
      `apiType = 'no_vehicle'`), **catché** ici (et dans `testConnection()`) pour retourner `[]`
      (resp. `{ok:true, count:0}`) au lieu de laisser remonter l'erreur.
  - `stellantisException` **remonte** pour tout type autre que `no_vehicle` (pas de catch générique :
    les appelants — UC06 sync, UC04 — mappent).
- `private static function energieDepuisEngine(array $engines): string` — **vocabulaire projet
  normalisé** : `Electric` | `Thermal` | `Hybrid` | `''` (inconnu).
  - Basé sur la **présence** des classes (pas sur `count()` — un bi-moteur 100 % électrique a 2
    entrées `Electric`), comparaison **insensible à la casse** (`strtolower` — le schéma PSA a des
    variations de casse avérées, cf. data-model § 0).
  - Electric présent seul → `Electric` ; Thermic présent seul → `Thermal` ; les deux → `Hybrid`.
  - ⚠️ **Vocabulaire unique 05/07** (advisor 2026-07-06, documenté aussi dans
    `stellantis-data-model.md`) : l'UC07 mappera `energies[].type` du `/status` (`Fuel`/`Electric`)
    vers **ce même vocabulaire** (`Fuel → Thermal`) — table de correspondance unique, pas deux enums
    parallèles. Le `energy` de la découverte est **indicatif** (nommage + jeu initial de commandes) ;
    la source de vérité au fil de l'eau est le `/status`.

## Server vs Client
100 % serveur. Pas d'UI, pas d'AJAX (bouton « Synchroniser » = UC06). Aucune chaîne i18n
(méthode de données ; logs non enveloppés — décision tracée).

## Validation
- Défensif sur la forme (isset/is_array à chaque niveau, cast string des champs).
- Jamais de VIN dans les logs (convention CLAUDE.md) — comptes rendus en nombre de véhicules.

## Server Actions / API
```php
stellantis::discoverVehicles(): array  // liste normalisée, throws stellantisException
```

## Dépendances
Aucune.
