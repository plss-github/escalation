<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight("config", UPDATE);

$step_id = (int) ($_POST['plugin_escalation_steps_id'] ?? 0);
$flow_id = (int) ($_POST['plugin_escalation_flows_id'] ?? 0);

// ---- Adicionar usuário específico ------------------------------------------
if (isset($_POST['add'])) {
  $users_id = (int) ($_POST['users_id'] ?? 0);
  if ($users_id > 0 && $step_id > 0) {
    $su = new PluginEscalationStepUser();
    $su->add(['plugin_escalation_steps_id' => $step_id, 'users_id' => $users_id]);
  }
  Html::redirect(PluginEscalationStep::getFormURL() . "?id=$step_id");
}

// ---- Remover usuário específico --------------------------------------------
if (isset($_POST['purge'])) {
  $id = (int) ($_POST['id'] ?? 0);
  if ($id > 0) {
    $su = new PluginEscalationStepUser();
    $su->delete(['id' => $id], 1);
  }
  Html::redirect(PluginEscalationStep::getFormURL() . "?id=$step_id");
}

Html::back();
