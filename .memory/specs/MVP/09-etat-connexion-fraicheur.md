# 09 — État de connexion & fraîcheur de la donnée

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/stellantis.class.php`

## Objectif
Exposer à l'utilisateur **l'état du lien** (connecté / token expiré / mode privacy) et **la fraîcheur**
de la télémétrie (horodatage de la dernière donnée remontée par le véhicule), pour qu'il interprète
correctement les valeurs (qui peuvent être anciennes sans wakeup).

## Périmètre
- **Inclus** : commande info `last_update` (horodatage du `/status`), indicateur `plugin_state`
  (OK / non authentifié / privacy / erreur), affichage page plugin + page Santé.
- **Exclu** : la régulation/robustesse fine (→ UC72) ; le mode privacy détaillé (→ UC75).

## Détails techniques
- `last_update` : extraire l'horodatage de fraîcheur du `/status` (champ global `updatedAt`/`lastUpdate`
  ou le plus récent des sous-objets) → commande info `string`/`numeric` (timestamp). Permet à l'UI de
  dire « donnée vieille de N min ».
- État global : méthode `stellantis::connectionState(): array` → `['state'=>'ok|unauthenticated|privacy|error','detail'=>…]`
  dérivé du dernier appel (token présent ? `invalid_grant` ? réponse vide = privacy possible ?).
- Restitution : ligne(s) dans `stellantis::health()` (page Santé Jeedom) + bandeau sur la page plugin.

## Critères d'acceptation
- [ ] Chaque véhicule expose une info `last_update` reflétant la fraîcheur réelle de la donnée.
- [ ] L'utilisateur voit clairement si le plugin est authentifié ou s'il doit se reconnecter.
- [ ] Un véhicule en mode privacy (réponse vide) est signalé sans être traité comme une erreur dure.

## Notes / risques
- La distinction « privacy » vs « erreur réseau » vs « pas encore de donnée » peut être ambiguë au début
  → affiner en UC75. Ici, viser un état lisible et non alarmiste.
