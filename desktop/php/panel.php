<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

// UC32 — Page-panneau « Mes véhicules », enregistrée au menu d'accueil via info.json "display" (toggle
// natif du core, rien à coder ici — cf. jeedom-panel-page-menu.md). Point d'entrée EXTERNE : n'appelle
// QUE stellantis:: (jamais stellantisApi::/stellantisException directement, cf. CLAUDE.md § autoload).
require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

// isConnect() (PAS admin) : page d'usage quotidien, pas une page de gestion du plugin.
// Chaîne en {{...}} (PAS __()) : convention des pages desktop/php/* de ce plugin (cf. desktop/php/stellantis.php),
// traduite par le pré-processeur de template du core, à la différence des points d'entrée core/ajax/*.
if (!isConnect()) {
  throw new Exception('{{401 - Accès non autorisé}}');
}

// Sélection : équipement activé + droit de lecture (instance) + case « Afficher sur le panneau carte »
// (défaut 1, posé par le plugin — cf. stellantis::assurerVisiblePanelParDefaut).
$vehicules = array();
foreach (eqLogic::byType('stellantis') as $eqLogic) {
  if (!$eqLogic->getIsEnable() || !$eqLogic->hasRight('r')) {
    continue;
  }
  if (!$eqLogic->getConfiguration('isVisiblePanel', 1)) {
    continue;
  }
  $vehicules[] = $eqLogic;
}
?>
<div class="row row-overflow">
  <div class="col-xs-12">
    <legend><i class="fas fa-map-marked-alt"></i> {{Mes véhicules}}</legend>
    <?php if (count($vehicules) == 0) { ?>
    <div class="alert alert-info">{{Aucun véhicule à afficher sur la carte}}</div>
    <?php } else { ?>
    <div class="alert alert-info">
      <i class="fas fa-info-circle"></i>
      {{Les coordonnées sont transmises à OpenStreetMap pour afficher la carte}}
    </div>
    <div class="row">
      <?php foreach ($vehicules as $eqLogic) {
        $nom = htmlspecialchars($eqLogic->getHumanName(true, true), ENT_QUOTES, 'UTF-8');

        // Position : lue depuis la commande info 'position' ("lat,lon"), revalidée numériquement avant
        // toute construction d'URL (défense en profondeur — ne jamais faire confiance à la valeur stockée).
        $cmdPosition = $eqLogic->getCmd('info', 'position');
        $valeurPosition = is_object($cmdPosition) ? trim((string) $cmdPosition->execCmd()) : '';
        $parties = ($valeurPosition != '') ? explode(',', $valeurPosition) : array();
        $lat = null;
        $lon = null;
        if (count($parties) == 2 && is_numeric(trim($parties[0])) && is_numeric(trim($parties[1]))) {
          $latCandidat = (float) trim($parties[0]);
          $lonCandidat = (float) trim($parties[1]);
          if (abs($latCandidat) <= 90 && abs($lonCandidat) <= 180) {
            $lat = $latCandidat;
            $lon = $lonCandidat;
          }
        }
        $aPosition = ($lat !== null && $lon !== null);
      ?>
      <div class="col-md-4 col-sm-6">
        <div class="panel panel-default">
          <div class="panel-heading"><strong><?php echo $nom; ?></strong></div>
          <div class="panel-body text-center">
            <?php if ($aPosition) {
              // Tuile INLINE en data: URI (appel serveur direct à renderStaticMap, autorisé par la CSP
              // `data:` — cf. § « data: URI vs proxy » de la spec technique). Ne lève jamais : au pire un
              // placeholder PNG, jamais une exception qui casserait toute la page panel.
              $tuile = stellantis::renderStaticMap((int) $eqLogic->getId());
              $dataUri = 'data:' . $tuile['type'] . ';base64,' . base64_encode($tuile['body']);
              // Notation décimale US indépendante de la locale (cf. stellantis::formaterCoordonnee) : un
              // simple (string) $lat produirait "48,8566" sur un Jeedom en locale fr_FR (LC_NUMERIC),
              // cassant les URLs OSM/geo: et l'affichage.
              $latStr = stellantis::formaterCoordonnee($lat);
              $lonStr = stellantis::formaterCoordonnee($lon);
              $latTexte = htmlspecialchars($latStr, ENT_QUOTES, 'UTF-8');
              $lonTexte = htmlspecialchars($lonStr, ENT_QUOTES, 'UTF-8');
              $urlOsm = 'https://www.openstreetmap.org/?mlat=' . rawurlencode($latStr) . '&mlon=' . rawurlencode($lonStr) . '#map=16/' . rawurlencode($latStr) . '/' . rawurlencode($lonStr);
              $urlGeo = 'geo:' . rawurlencode($latStr) . ',' . rawurlencode($lonStr) . '?z=16';
              $cmdMaj = $eqLogic->getCmd('info', 'position_updated');
              $valeurMaj = is_object($cmdMaj) ? trim((string) $cmdMaj->execCmd()) : '';
            ?>
            <img src="<?php echo $dataUri; ?>" alt="<?php echo $nom; ?>" style="max-width:100%;border-radius:4px;">
            <div style="margin-top:8px;"><?php echo $latTexte . ', ' . $lonTexte; ?></div>
            <?php if ($valeurMaj != '') { ?>
            <div class="text-muted small">{{Position mise à jour}} : <?php echo htmlspecialchars($valeurMaj, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>
            <div style="margin-top:8px;">
              <a href="<?php echo htmlspecialchars($urlOsm, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt"></i> {{Voir sur la carte}}</a>
              <a href="<?php echo htmlspecialchars($urlGeo, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="{{Voir sur la carte}}" style="margin-left:8px;"><i class="fas fa-mobile-alt"></i></a>
            </div>
            <?php } else { ?>
            <div class="text-muted"><i class="fas fa-map-marker-alt"></i> {{Position non disponible}}</div>
            <div class="text-muted small">{{Vie privée activée ou véhicule non localisé}}</div>
            <?php } ?>
          </div>
        </div>
      </div>
      <?php } ?>
    </div>
    <?php } ?>
  </div>
</div>
