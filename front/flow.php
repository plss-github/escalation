<?php

if (!defined('GLPI_ROOT')) {
  include ('../../../inc/includes.php');
}

Session::checkRight("config", READ);

Html::header(PluginEscalationFlow::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], "config", "pluginescalationflow");

Search::show('PluginEscalationFlow');

Html::footer();
