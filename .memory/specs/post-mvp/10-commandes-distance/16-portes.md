# 16 — Verrouillage / déverrouillage des portes

**Domaine :** Commandes à distance · **Dépend de :** UC11, UC12 · **Statut :** à spécifier

## Objectif / valeur
Verrouiller/déverrouiller le véhicule à distance depuis Jeedom (scénarios sécurité : verrouiller le soir,
déverrouiller à l'approche…).

## Périmètre
- **Inclus** : commandes action `lock` / `unlock` (publish MQTT), MAJ de l'info `doors_locked`.
- **Exclu** : ouvrants détaillés en lecture (→ UC44).

## Détails techniques
- Publish MQTT (démon) : payload `{"action":"lock"|"unlock"}` (cf. `RemoteClient`).
- Lier à l'info `doors_locked` (MVP07).
- ⚠️ **Sensible** (sécurité) : envisager la **confirmation Jeedom** (`cmd` avec confirmation/PIN) avant un
  `unlock`. Le déverrouillage peut être **indisponible** sur véhicules thermiques / selon équipement.

## Critères d'acceptation
- [ ] « Verrouiller/Déverrouiller » publie la commande ; `doors_locked` reflète l'état après ack.
- [ ] Le déverrouillage demande une confirmation (anti-fausse-manip), si activé.
- [ ] Indisponibilité (thermique/équipement) signalée proprement.

## À confirmer
- Payload exact et disponibilité réelle du `unlock` selon modèle/motorisation.
