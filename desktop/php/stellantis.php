<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('stellantis');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
// UC09 — État du lien au compte (sans appel réseau). detail déjà traduit par connectionState().
$etatConnexion = stellantis::connectionState();
$niveauConnexion = ($etatConnexion['state'] == 'ok') ? 'success' : (($etatConnexion['state'] == 'unauthenticated') ? 'warning' : 'danger');
$iconeConnexion = ($etatConnexion['state'] == 'ok') ? 'fa-check-circle' : (($etatConnexion['state'] == 'unauthenticated') ? 'fa-exclamation-triangle' : 'fa-times-circle');
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<!-- Bandeau d'état de la connexion au compte Stellantis (UC09) -->
		<div class="alert alert-<?php echo $niveauConnexion; ?>" style="margin-bottom:10px;">
			<i class="fas <?php echo $iconeConnexion; ?>"></i>
			<strong>{{État de la connexion}} :</strong>
			<?php echo htmlspecialchars($etatConnexion['detail'], ENT_QUOTES, 'UTF-8'); ?>
		</div>
		<!-- UC77 : consommation de l'API REST (lecture cache seule, restitution serveur au chargement) -->
		<div class="alert alert-info" style="margin-bottom:10px;">
			<strong><i class="fas fa-chart-bar"></i> {{Consommation de l'API REST}}</strong>
			<?php
			$statsApi = stellantis::recapStatistiquesApi();
			if ($statsApi['today']['total'] == 0) {
				echo '<br>{{Aucun appel enregistré}}';
			} else {
				echo '<br>{{Appels aujourd\'hui}} : ' . (int) $statsApi['today']['total'];
				echo ' &mdash; {{Sur 7 jours}} : ' . (int) $statsApi['total_periode'];
				if (count($statsApi['par_compte']) > 1) {
					foreach ($statsApi['par_compte'] as $slotCompte => $totalCompte) {
						echo '<br>{{Compte}} ' . (int) $slotCompte . ' : ' . (int) $totalCompte;
					}
				}
				if (count($statsApi['today']['byEndpoint']) > 0) {
					arsort($statsApi['today']['byEndpoint']);
					$parts = array();
					foreach ($statsApi['today']['byEndpoint'] as $label => $count) {
						$parts[] = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ' (' . (int) $count . ')';
					}
					echo '<br>{{Détail par endpoint}} : ' . implode(', ', $parts);
				}
			}
			?>
		</div>
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<!-- Boutons de gestion du plugin -->
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor logoSecondary" id="stellantis_btTestConnexion">
				<i class="fas fa-plug"></i>
				<br>
				<span>{{Tester la connexion}}</span>
			</div>
			<div class="cursor logoSecondary" id="stellantis_btSync">
				<i class="fas fa-sync"></i>
				<br>
				<span>{{Synchroniser les véhicules}}</span>
			</div>
		</div>
		<legend><i class="fas fa-table"></i> {{Mes véhicules}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun véhicule trouvé, cliquer sur "Synchroniser les véhicules" pour commencer}}</div>';
		} else {
			// Champ de recherche
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
			echo '<div class="input-group-btn">';
			echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
			echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
			echo '</div>';
			echo '</div>';
			// Liste des équipements du plugin
			echo '<div class="eqLogicThumbnailContainer">';
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $eqLogic->getImage() . '"/>';
				echo '<br>';
				echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
				echo '<span class="hiddenAsCard displayTableRight hidden">';
				echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
				echo '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		?>
	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de présentation de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<!-- barre de gestion de l'équipement -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<!-- Partie gauche de l'onglet "Equipements" -->
				<!-- Paramètres généraux et spécifiques de l'équipement -->
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-6">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>

							<legend><i class="fas fa-cogs"></i> {{Paramètres spécifiques}}</legend>
							<!-- Champs renseignés automatiquement par la synchronisation (lecture seule) — voir bouton « Synchroniser les véhicules » -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{VIN}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Numéro d'identification du véhicule (renseigné par la synchronisation)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vin" readonly>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{ID API}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Identifiant du véhicule côté API Stellantis (différent du VIN, requis pour les appels)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="apiId" readonly>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Marque}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Marque du véhicule (renseignée par la synchronisation)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="brand" readonly>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Libellé du véhicule}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Nom du véhicule tel que défini dans l'application mobile (pré-rempli avec la désignation commerciale, modifiable ; renseigné par la synchronisation)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="label" readonly>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Motorisation}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Motorisation détectée (Electric / Thermal / Hybrid) — indicative, affinée par le suivi de statut}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="energy" readonly>
								</div>
							</div>
							<!-- UC54 : compte Stellantis de rattachement (1 = principal/pilotage à distance, 2/3 = secondaires
							     lecture seule). Réécrit à chaque synchronisation comme brand/energy ci-dessus, comme
							     accountSlotLabel — même pattern readonly. ⚠️ Champ CACHÉ MAIS OBLIGATOIRE : 'accountSlot' (la
							     clé de ROUTAGE réelle, consommée par accountSlotDe()/refreshTelemetry/createCommands/cron)
							     doit être re-soumise à chaque Sauvegarder comme vin/apiId/brand/label/energy ci-dessus, sinon
							     un Sauvegarder sur un véhicule de compte secondaire risquerait de la réinitialiser au compte
							     principal (comportement de fusion utils::a2o() non vérifié — ne pas s'y fier). Le libellé
							     lisible (accountSlotLabel) reste affiché séparément, en readonly. -->
							<input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="accountSlot">
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Compte du véhicule}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Compte Stellantis auquel ce véhicule est rattaché (renseigné par la synchronisation) — seul le compte principal permet le pilotage à distance}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="accountSlotLabel" readonly>
								</div>
							</div>
							<!-- Exemple de champ de saisie du cron d'auto-actualisation avec assistant -->
							<!-- La fonction cron de la classe du plugin doit contenir le code prévu pour que ce champ soit fonctionnel -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Auto-actualisation}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Fréquence de rafraîchissement des commandes infos de l'équipement}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="autorefresh" placeholder="{{Cliquer sur ? pour afficher l'assistant cron}}">
										<span class="input-group-btn">
											<a class="btn btn-default cursor jeeHelper roundedRight" data-helper="cron" title="{{Assistant cron}}">
												<i class="fas fa-question-circle"></i>
											</a>
										</span>
									</div>
								</div>
							</div>
							<!-- UC76 : synchronisation sélective — inclure/exclure ce véhicule du rafraîchissement
							     automatique (cron). L'équipement reste ACTIVÉ (isEnable=1) : ses dernières valeurs sont
							     conservées, seul le polling périodique est sauté (cf. cron()). Défaut coché (posé par
							     stellantis::assurerSyncEnabledParDefaut à la création ET au backfill des véhicules
							     existants), même précédent qu'isVisiblePanel ci-dessous. -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Inclure dans le rafraîchissement auto}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Inclure ce véhicule dans le rafraîchissement périodique automatique. Décochez pour l'exclure (économie de quota / anti-ban) sans le supprimer : ses dernières valeurs sont conservées.}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="syncEnabled" checked>
									</label>
								</div>
							</div>
							<!-- UC24 : suivi & statistiques de charge — 2 champs de config éditables (PAS readonly, à la
							     différence des champs de synchro ci-dessus) : une clé de config absente du formulaire est
							     effacée au Sauvegarder. -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Capacité batterie}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Capacité utile de la batterie de traction en kWh — saisie manuelle, sert à estimer l'énergie ajoutée lors d'une charge (laisser vide si inconnue)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="battery_capacity">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Tarif électricité}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Prix du kWh en euros — sert à estimer le coût d'une charge (informatif, laisser vide pour ne pas estimer le coût)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="0.01" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="charge_tarif">
								</div>
							</div>
							<!-- UC41 : kilométrage & entretien — 2 champs de config éditables (PAS readonly, même
							     précédent que battery_capacity/charge_tarif ci-dessus) : une clé de config absente du
							     formulaire est effacée au Sauvegarder. Seuils PAR VÉHICULE (repli sur le défaut
							     1000 km / 30 j si laissés vides). -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Seuil d'alerte révision (km)}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Distance restante en dessous de laquelle la révision est signalée proche — laisser vide pour utiliser le défaut (1000 km)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="service_alert_km">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Seuil d'alerte révision (jours)}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Nombre de jours restants en dessous duquel la révision est signalée proche — laisser vide pour utiliser le défaut (30 jours)}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="service_alert_days">
								</div>
							</div>
							<!-- UC73 : réveil automatique adaptatif — opt-in, DÉSACTIVÉ par défaut (pas d'attribut
							     "checked", à la différence d'isVisiblePanel ci-dessous, AC1). Réveille le véhicule via
							     MQTT (wakeup UC13) pour rafraîchir la télémétrie qu'un simple polling REST ne peut pas
							     obtenir ; consomme la batterie de servitude 12 V, d'où l'avertissement VISIBLE (pas
							     qu'un tooltip). Cadences éditables (clé absente du formulaire = effacée au Sauvegarder,
							     même précédent que battery_capacity/service_alert_* ci-dessus) ; le serveur reste
							     autoritaire (clamp 5..1440 min dans cadenceAutoWakeupSecondes()). -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Réveil automatique adaptatif}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Réveille périodiquement le véhicule pour rafraîchir la télémétrie (batterie, position, charge…) que le polling REST seul ne peut pas obtenir. Cadence adaptative : fréquente en charge, rare en veille. Nécessite l'activation de l'OTP (pilotage à distance).}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="auto_wakeup">
									</label>
									<div class="alert alert-warning" style="margin-top:5px;margin-bottom:0;">
										<i class="fas fa-exclamation-triangle"></i>
										{{⚠️ Risque batterie 12 V : réveiller le véhicule consomme la batterie de servitude. Un usage excessif peut la décharger (démarrage / accès sans clé inopérants). À n'activer qu'en connaissance de cause.}}
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Cadence de réveil en charge (min)}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Fréquence de réveil automatique quand le véhicule est en charge — minimum 5 min (protection anti-ban / batterie). Sans effet sur un véhicule thermique. Laisser vide pour le défaut (5 min).}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="auto_wakeup_charge_min" placeholder="5">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Cadence de réveil en veille (min)}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Fréquence de réveil automatique quand le véhicule est à l'arrêt — plus la valeur est élevée, plus la batterie 12 V est préservée. Laisser vide pour le défaut (60 min).}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="auto_wakeup_idle_min" placeholder="60">
								</div>
							</div>
							<!-- UC32 : panneau carte « Mes véhicules » — sélection par véhicule. Défaut (coché) posé par le
							     plugin (stellantis::assurerVisiblePanelParDefaut) à la création ET au backfill des véhicules
							     existants. Clé de configuration OBLIGATOIRE dans ce formulaire (sinon effacée au Sauvegarder,
							     cf. jeedom-eqlogic-sync-persist.md). -->
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Afficher sur le panneau carte}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Affiche ce véhicule dans le panneau « Mes véhicules » du menu}}"></i></sup>
								</label>
								<div class="col-sm-6">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="isVisiblePanel" checked>
									</label>
								</div>
							</div>
						</div>

						<!-- Partie droite de l'onglet "Équipement" -->
						<!-- Affiche un champ de commentaire par défaut mais vous pouvez y mettre ce que vous voulez -->
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Description}}</label>
								<div class="col-sm-6">
									<textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea>
								</div>
							</div>
						</div>
					</fieldset>
				</form>
			</div><!-- /.tabpanel #eqlogictab-->

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<a class="btn btn-default btn-sm pull-right cmdAction" data-action="add" style="margin-top:5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une commande}}</a>
				<br><br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th>{{Type}}</th>
								<th style="min-width:260px;">{{Options}}</th>
								<th>{{Etat}}</th>
								<th style="min-width:80px;width:200px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'stellantis', 'js', 'stellantis'); ?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js'); ?>
