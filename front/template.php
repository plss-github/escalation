<?php
if (!defined('GLPI_ROOT')) { include('../../../inc/includes.php'); }
Session::checkRight('config', READ);
Html::header(PluginEscalationTemplate::getTypeName(2), $_SERVER['PHP_SELF'], 'config', 'pluginescalationflow', 'template');
Search::show('PluginEscalationTemplate');
Html::footer();
