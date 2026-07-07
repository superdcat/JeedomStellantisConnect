# 61 — Extraction automatique des identifiants (Client ID / Client Secret) depuis l'APK

**Domaine :** Configuration avancée · **Dépend de :** MVP/01 (config plugin) · **Statut :** à spécifier (tech)

## Objectif / valeur
Éviter à l'utilisateur la manipulation manuelle (installer Python/pip, `psa-car-controller`, lancer un
script) documentée en MVP/01 pour obtenir son `client_id`/`client_secret` : un bouton sur la page de
configuration du plugin télécharge l'APK de sa marque, en extrait les identifiants, et **pré-remplit**
les champs — sans quitter Jeedom.

> ⚠️ Ceci **n'annule pas** la décision actée en MVP (cf. `stellantis-api-architecture.md` § 4.1, option
> (a) retenue) : chaque installation continue de faire **sa propre** extraction, à la demande, et aucun
> secret n'est figé/redistribué dans le code du plugin. Cette UC automatise uniquement la corvée
> manuelle ; la saisie manuelle documentée reste le chemin de repli garanti. Voir aussi § « Notes /
> risques » ci-dessous pour la distinction avec l'option « hardcoder les valeurs » (écartée).

## Périmètre
- **Inclus** : téléchargement de l'APK de la marque configurée (source par défaut
  `flobz/psa_apk`, configurable), décompression, extraction des ressources brutes nécessaires
  (`client_id`/`client_secret`), pré-remplissage des champs de config, nettoyage du fichier téléchargé,
  dégradation propre vers la procédure manuelle en cas d'échec.
- **Exclu** : extraction de `host_brandid_prod` / des certificats mTLS (`public.pem`/`private.pem`) —
  non nécessaires au MVP lecture, potentiellement utiles au post-MVP commandes (à ré-évaluer si besoin) ;
  pas de récupération depuis les stores officiels (Google Play/App Store), seulement depuis l'archive
  communautaire déjà utilisée par la procédure manuelle.

## Détails techniques

### Constat clé qui rend cette UC réalisable en PHP pur (à confirmer empiriquement, cf. « À confirmer »)
Un APK est un fichier **ZIP**. Les ressources dites *raw* d'Android (`res/raw/...`,
`res/raw-{lang}-r{country}/...`) sont, à la différence des ressources compilées (`resources.arsc`,
`AndroidManifest.xml`), stockées **telles quelles** dans ce ZIP — de simples entrées de fichier
retrouvables par leur chemin exact. C'est exactement ce qu'utilise `psa_car_controller` pour
`cultures.json` et `parameters.json` (cf. `apk_parser.py`, étapes 1-3 de
`stellantis-implementations-reference.md`) ; seule l'extraction de `HOST_BRANDID_PROD` (une *string
resource* compilée) exige un vrai décodeur de ressources Android (androguard, côté PSACC). **Comme
cette UC n'a pas besoin de `host_brandid_prod`** (exclu du périmètre), l'extraction complète
`client_id`/`client_secret` est réalisable avec `ZipArchive` (extension PHP standard), **sans
androguard, sans Python, sans démon** — cohérent avec la philosophie « MVP 100 % PHP ».

### Étapes
1. Télécharger `{fichier-marque}.apk.bz2` depuis une URL par marque — défaut
   `https://github.com/flobz/psa_apk/raw/main/{fichier}.apk.bz2` (table `stellantis::BRANDS` étendue
   avec le nom de fichier, ex. `mypeugeot`/`mycitroen`/`myds`/`myopel`/`myvauxhall`), **surchargeable**
   en config avancée (dépendance à un dépôt communautaire tiers, cf. « Notes / risques »).
2. Décompresser le `.bz2` (extension PHP `ext-bz2` : `bzdecompress()` ou stream `compress.bzip2://`).
3. Ouvrir l'APK obtenu avec `ZipArchive`, lire `res/raw/cultures.json` → retrouver la culture
   (`{lang}-r{country}`, ex. `fr-rFR`) correspondant au `country` déjà configuré (MVP/01).
4. Lire `res/raw-{lang}-r{country}/parameters.json` → `client_id = cvsClientId`,
   `client_secret = cvsSecret`.
5. Proposer les valeurs à l'utilisateur (pré-remplissage des champs, **pas de sauvegarde silencieuse**
   — l'admin valide/sauvegarde comme aujourd'hui).
6. **Supprimer** le fichier APK et son intermédiaire `.bz2` immédiatement après extraction — ne jamais
   les persister sur le disque Jeedom.

### Contraintes opérationnelles
- **Poids réel confirmé** : l'APK observé pèse **~100 Mo**. Sur Raspberry Pi (carte SD, connexion
  parfois lente), c'est significatif → action **strictement manuelle** (bouton dédié « Extraire
  automatiquement »), **jamais** déclenchée par le cron ou à la sauvegarde de la config ; timeout HTTP
  généreux et message de progression ; on ne télécharge **que** la marque configurée (jamais les 5).
- **Nouvelles dépendances PHP** : `ext-zip` (quasi toujours présente) et surtout `ext-bz2` (moins
  garantie sur toutes les installations) → vérifier leur présence, message actionnable + repli vers la
  procédure manuelle documentée si absentes (jamais un crash ou un blocage du plugin).
- **Piste d'optimisation non bloquante pour une v1** : téléchargement partiel par requêtes HTTP `Range`
  (lire d'abord la fin du ZIP = table centrale, puis seulement l'entrée ciblée) pour éviter de rapatrier
  ~100 Mo pour quelques Ko utiles. À évaluer seulement si le coût actuel s'avère gênant en usage réel —
  ne pas complexifier la première version pour ça.
- **Échecs attendus** (réseau, chemin introuvable, structure d'APK modifiée par Stellantis, extension
  PHP manquante) → message actionnable renvoyant explicitement vers la procédure manuelle déjà
  documentée (`docs/<langue>/index.md`), jamais un échec silencieux ni un état de config incohérent.

## Critères d'acceptation
- [ ] Un clic sur « Extraire automatiquement » (marque + pays déjà renseignés) pré-remplit Client ID et
      Client Secret sans que l'utilisateur installe quoi que ce soit en dehors de Jeedom.
- [ ] Le fichier APK/`.bz2` téléchargé n'est **jamais** conservé au-delà de l'extraction.
- [ ] Sur un environnement sans `ext-bz2`/`ext-zip`, message clair + renvoi vers la procédure manuelle
      (pas de crash, pas de blocage du reste de la page de config).
- [ ] Un échec (réseau, format, chemin introuvable) laisse l'utilisateur libre de saisir les
      identifiants manuellement ensuite — aucun état bloquant.
- [ ] Seule la marque configurée est téléchargée ; jamais les 5 en une fois.

## Notes / risques
- **Ne remplace pas** la saisie manuelle : reste une **option**, l'utilisateur peut toujours saisir ses
  identifiants lui-même (procédure MVP/01 inchangée) si l'extraction auto échoue ou est indisponible.
- **Distinct de « hardcoder les valeurs dans le plugin »** (option explicitement écartée, échange
  2026-07-07) : ici, le plugin télécharge **à l'exécution, chez l'utilisateur**, depuis la même archive
  communautaire (`flobz/psa_apk`) que la procédure déjà documentée — aucune valeur n'est figée ni
  republiée dans le dépôt/code du plugin. L'exposition légale reste donc bornée à « le plugin automatise
  ce que l'utilisateur devait déjà faire à la main », pas à « le projet redistribue les secrets
  Stellantis en clair dans son historique git ».
- **Dépendance de fait à `flobz/psa_apk`** (disponibilité, rythme de mise à jour des APK archivés) :
  prévoir une URL **configurable** par marque plutôt qu'une URL unique et figée, pour absorber une
  éventuelle indisponibilité ou un déplacement du dépôt source.
- **Bandeau ToS/risque** (déjà prévu en UC82 pour la procédure manuelle) doit couvrir explicitement
  cette fonctionnalité : le plugin télécharge et analyse un APK tiers pour en extraire un secret
  propriétaire, ce qui reste couvert par l'avertissement général « API non officielle », mais mérite
  d'être rappelé au moment du clic (ex. confirmation avant le premier lancement).

## À confirmer
- **Hypothèse structurante non testée empiriquement dans ce projet** : que `res/raw.../parameters.json`
  et `res/raw/cultures.json` sont bien lisibles via `ZipArchive::getFromName()` en PHP pur, sans passer
  par un décodeur de ressources Android complet. Fortement probable (comportement documenté des
  ressources *raw* Android + confirmé indirectement par le fait que seule `HOST_BRANDID_PROD`, exclue du
  périmètre, nécessite androguard côté `psa_car_controller`) — **à vérifier sur un APK réel** au moment
  de l'implémentation, avant d'engager le développement complet.
- Disponibilité de l'extension PHP `bz2` sur les installations Jeedom courantes (Debian/Raspberry Pi) —
  si trop rare, évaluer une alternative (ex. accepter aussi un `.apk` déjà décompressé déposé par
  l'utilisateur, en secours).
- Comportement si Stellantis fait évoluer la structure interne de l'APK (dossier `raw` renommé, JSON
  restructuré, clés renommées) : s'assurer que l'échec est détecté clairement et n'est jamais interprété
  comme un succès partiel.
- Table `fichier APK par marque` (`mypeugeot`/`mycitroen`/`myds`/`myopel`/`myvauxhall`) déjà connue
  (cf. doc utilisateur MVP/01) — à réutiliser telle quelle, ne pas la redéfinir.
