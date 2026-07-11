# Spec technique — 21 (Détail batterie & charge)

> Spec fonctionnelle : `21-detail-batterie-charge.md`. Feature **100 % lecture / parsing** : aucun nouvel
> appel réseau, aucune commande MQTT, aucune dépendance. Consomme le `GET /user/vehicles/{id}/status`
> déjà récupéré par `refreshTelemetry()` (UC08) à chaque cron.

## Architecture

**Un seul fichier modifié : `core/class/stellantis.class.php`.** Aucun autre composant (AJAX, JS, config,
`packages.json`, démon) n'est touché.

5 nouvelles **commandes info**, dérivées du `/status` (source : `.memory/analyse/stellantis-data-model.md`
§ 2.1 / § 2.6, clés **snake_case**, cohérentes avec le code UC14 existant qui lit déjà
`charging.next_delayed_time`) :

| logicalId | Champ `/status` | subType / unité | Historisé | Transformation |
|---|---|---|---|---|
| `charging_rate` | `energies[].charging.charging_rate` | numeric / `km/h` | oui | cast `(float)` |
| `charging_remaining` | `energies[].charging.remaining_time` (`PT1H30M`) | numeric / `min` | oui | ISO 8601 → **minutes** (`dureeIsoEnMinutes`) |
| `charging_mode` | `energies[].charging.charging_mode` (`No`/`Slow`/`Quick`) | string / — | non | assaini `[^A-Za-z]` (comme `charging_status`) |
| `charge_next_time` | `energies[].charging.next_delayed_time` (RFC3339 **ou** `PT..`) | string / — | non | garde de format → `parseHeureIso()` → `"HH:MM"` |
| `battery_12v` | `battery.voltage` (**racine**, § 2.6) | numeric / `V` | oui | cast `(float)` |

### Modifications précises

1. **`definitionsCommandes()`** (~l.529) — ajouter les 5 entrées. Noms FR **littéraux** `__()` (extracteur
   i18n). `generic_type` vide partout (aucune constante core vérifiée — convention « on ne devine pas »).
   ```php
   'charging_rate'      => array(__('Vitesse de charge', __FILE__), 'numeric', '', 'km/h', true),
   'charging_remaining' => array(__('Temps de charge restant', __FILE__), 'numeric', '', 'min', true),
   'charging_mode'      => array(__('Mode de charge', __FILE__), 'string', '', '', false),
   'charge_next_time'   => array(__('Prochaine charge programmée', __FILE__), 'string', '', '', false),
   'battery_12v'        => array(__('Batterie 12 V', __FILE__), 'numeric', '', 'V', true),
   ```

2. **`parseStatus()`** (~l.791) :
   - Dans la branche **`type == 'electric'`** (donc VE/PHEV uniquement — respecte AC3 pour ces 4 champs),
     mapper `charging_rate`, `charging_remaining`, `charging_mode`, `charge_next_time`, chacun **seulement
     si le champ est présent** (`isset` + `is_numeric`/`is_scalar`). `charging_mode` assaini via
     `preg_replace('/[^A-Za-z]/', '', …)` comme `charging_status`.
   - `charge_next_time` : **garde de format obligatoire** avant de formater — n'émettre la clé que si le
     brut matche `^\s*PT` **ou** `T\d{2}:\d{2}`, puis `parseHeureIso()` → `sprintf('%02d:%02d', $h, $m)`.
     ⚠️ Ne PAS appeler `parseHeureIso()` sans cette garde : sur un format inconnu il renvoie `[0,0]` →
     `"00:00"` fabriqué, ce qui violerait le contrat de `parseStatus` (champ invalide ⇒ clé absente,
     jamais de valeur inventée). `parseHeureIso()` reste **inchangé** (contrat UC14 préservé).
   - **`battery_12v` — UNIVERSEL** (décision validée 2026-07-11, cf. § dédié) : mapper **hors** de la
     boucle énergies, depuis la racine `battery.voltage`, **sans garde de motorisation**, dès que présent
     et numérique. Commentaire explicite : `battery.voltage` racine = **batterie 12 V de servitude**,
     DISTINCT de `energy[].battery.*` (capacité/SOH batterie de traction, futur UC dédié).

3. **Nouveau helper pur `dureeIsoEnMinutes(string): ?int`** (près de `parseHeureIso`) — convertit une
   **durée** ISO 8601 en minutes. **Aucun clamp** (une durée peut dépasser 24 h : `PT25H` = 1500 min).
   Gère jours/heures/minutes/secondes ; `null` si aucun composant ne matche.
   ```php
   private static function dureeIsoEnMinutes(string $_iso): ?int {
     if (!preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', trim($_iso), $m)) {
       return null;
     }
     $j = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 0;
     $h = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
     $min = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
     $s = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
     if ($j == 0 && $h == 0 && $min == 0 && $s == 0 && strpos($_iso, '0') === false) {
       return null; // "PT" seul (aucun composant) → pas de valeur
     }
     return $j * 1440 + $h * 60 + $min + intdiv($s, 60);
   }
   ```
   (le dév ajustera la garde « PT seul » proprement ; l'essentiel : `null` sur non-match, pas de clamp.)

## Server vs Client

**100 % serveur (PHP)**, dans le cron de polling existant. Aucun code client : les commandes info sont
rendues par les widgets natifs du core selon leur `subType`/`unité`/`generic_type`. Rien à ajouter dans
`desktop/js` ni `desktop/php`.

## Validation

- **Serveur / parsing** : chaque champ mappé seulement si présent (`isset`) et du bon type
  (`is_numeric`/`is_scalar`). `charging_mode` assaini (`[^A-Za-z]`). `charge_next_time` gardé par format
  avant formatage (pas de `"00:00"` fabriqué). `dureeIsoEnMinutes` renvoie `null` sur non-match (la clé
  n'est alors pas émise). `battery.voltage` exposé **brut** (unité incertaine — dump e-C4 = 63.5 → aucune
  borne de vraisemblance, elle produirait des faux positifs).
- **Création conditionnelle** : `createCommands()` **inchangé**. Les 5 commandes sont créées
  **paresseusement** par la boucle existante de `refreshTelemetry()` (`foreach $valeurs → ensureCommand`)
  le jour où le champ apparaît — mécanisme déjà documenté dans le docblock de `createCommands()`.
  → AC1 (remontée VE/PHEV branché) et AC2 (durées numériques) satisfaits ; AC3 satisfait pour les 4
  `charging.*` **au niveau du parsing** (branche electric ⇒ jamais émis sur thermique pur).

## Server Actions / API

Aucune action distante, aucun endpoint AJAX, aucune signature publique nouvelle hormis le helper privé
`dureeIsoEnMinutes()`. Le flux est : `cron()` → `refreshTelemetry()` → `parseStatus()` (mapping enrichi)
→ `ensureCommand()` (création paresseuse) → `checkAndUpdateCmd()`.

**Non touché** : `extraireHeureChargeDifferee()` + cache `CHARGE_NEXT_TIME_KEY` (UC14, valeur **brute**
pour republication MQTT `charge_stop`) restent tels quels — `charge_next_time` expose une vue **HH:MM**
distincte du même champ, sans conflit.

## Dépendances

Aucune (pas de `packages.json`, pas d'extension PHP, pas de paquet Python).

## Décision — `battery_12v` universel (2026-07-11)

Écart **assumé et validé** vs la lettre d'AC3 (« aucune de ces commandes sur thermique pur »). Le
data-model § 2.6 documente `battery.voltage` comme un champ **racine universel** (batterie 12 V de
servitude, présente sur tout véhicule y compris thermique ; cas d'usage domotique réel = surveiller une
décharge en stationnement prolongé). `battery_12v` est donc mappé **sans garde de motorisation**. Seuls
les 4 champs `charging.*` (physiquement liés à la charge électrique) restent VE/PHEV.

## Hors périmètre (renvoyé à une UC dédiée)

SOH / santé et capacité de la **batterie de traction** (`energy[].battery.health.*`,
`extension.electric.battery.load.*`) — non listés au § Détails techniques de la spec fonctionnelle 21,
et le SOH n'est renseigné que pendant/juste après une charge (mise en cache horodatée à prévoir).

## i18n (FR uniquement — traduction déléguée au translator, étape 10)

5 nouvelles clés `__()` : `Vitesse de charge`, `Temps de charge restant`, `Mode de charge`,
`Prochaine charge programmée`, `Batterie 12 V`. Ne PAS toucher `core/i18n/*.json` pendant l'implémentation.
