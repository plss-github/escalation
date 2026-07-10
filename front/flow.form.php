<?php

if (!defined('GLPI_ROOT')) {
  include ('../../../inc/includes.php');
}

$flow = new PluginEscalationFlow();

if (isset($_POST["add"])) {
  $flow->check(-1, CREATE, $_POST);
  if ($newID = $flow->add($_POST)) {
    Html::redirect($flow->getFormURL()."?id=".$newID);
  }
  Html::back();
} else if (isset($_POST["update"])) {
  $flow->check($_POST["id"], UPDATE);
  $flow->update($_POST);
  Html::back();
} else if (isset($_POST["purge"])) {
  $flow->check($_POST["id"], PURGE);
  $flow->delete($_POST, 1);
  $flow->redirectToList();
}

Html::header(PluginEscalationFlow::getTypeName(Session::getPluralNumber()), $_SERVER["PHP_SELF"], "admin", "pluginescalationflow", "flow");

$flow->display([
  'id' => $_GET["id"] ?? -1
]);

Html::footer();
