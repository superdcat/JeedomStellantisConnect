# UC — Ouverture de porte / serrure

**Domaine :** Contrôle d'accès · **Dépend de :** MVP/06 · **Statut endpoints :** à confirmer

## Objectif / valeur
Pour les dispositifs compatibles (interphone/serrure connectée IMOU) : commander l'**ouverture
de porte** à distance depuis Jeedom.

## Ce que permet l'API
- Ouverture de porte à distance (`openDoorRemote` / équivalent), génération de clés/temporaires
  pour serrures (selon matériel).

## Esquisse Jeedom
- Commande action `open_door` — **sensible** : exiger une confirmation, tracer dans les logs.
- Créée uniquement si le matériel le supporte (capacité détectée).

## Critères d'acceptation
- [ ] (Si matériel présent) la commande ouvre bien la porte, avec confirmation + trace log.

## À confirmer
- Endpoint exact ; pré-requis de sécurité (vérification mot de passe device ?) ; matériel de test.
- ⚠️ UC sécurité-critique : encadrer fortement (droits, confirmation, audit).
