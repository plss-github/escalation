<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight("config", UPDATE);

$step    = new PluginEscalationStep();
$flow_id = (int) ($_POST['plugin_escalation_flows_id'] ?? $_GET['plugin_escalation_flows_id'] ?? 0);
$step_id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

// ---- Adicionar etapa -------------------------------------------------------
if (isset($_POST['add'])) {
  $step->check(-1, CREATE, $_POST);

  $order = PluginEscalationStep::getNextOrder($flow_id);
  $input = [
    'plugin_escalation_flows_id' => $flow_id,
    'name'                       => $_POST['name'],
    'type'                       => $_POST['type'],
    'step_order'                 => $order,
    'timeout_days'               => (int) ($_POST['timeout_days'] ?? 0),
    'hierarchy_level'            => (int) ($_POST['hierarchy_level'] ?? 0),
    'quorum_type'                => $_POST['quorum_type'] ?? PluginEscalationStep::QUORUM_MIN1,
    'include_requesters'         => !empty($_POST['include_requesters']) ? 1 : 0,
    'include_assigned'           => !empty($_POST['include_assigned']) ? 1 : 0,
    'include_observers'          => !empty($_POST['include_observers']) ? 1 : 0,
  ];

  if ($new_id = $step->add($input)) {
    Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$1");
  }
  Html::back();
}

// ---- Atualizar etapa -------------------------------------------------------
if (isset($_POST['update'])) {
  $step->check($step_id, UPDATE);
  $step->update([
    'id'                 => $step_id,
    'name'               => $_POST['name'],
    'type'               => $_POST['type'],
    'timeout_days'       => (int) ($_POST['timeout_days'] ?? 0),
    'hierarchy_level'    => (int) ($_POST['hierarchy_level'] ?? 0),
    'quorum_type'        => $_POST['quorum_type'] ?? PluginEscalationStep::QUORUM_MIN1,
    'include_requesters' => !empty($_POST['include_requesters']) ? 1 : 0,
    'include_assigned'   => !empty($_POST['include_assigned']) ? 1 : 0,
    'include_observers'  => !empty($_POST['include_observers']) ? 1 : 0,
  ]);
  Html::back();
}

// ---- Excluir etapa ---------------------------------------------------------
if (isset($_POST['purge'])) {
  $step->check($step_id, PURGE);
  $step->delete(['id' => $step_id], 1);
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$1");
}

// ---- Mover para cima -------------------------------------------------------
if (isset($_POST['move_up'])) {
  $step->getFromDB($step_id);
  $adjacent = PluginEscalationStep::getAdjacentStep($step_id, 'up');
  if ($adjacent) {
    PluginEscalationStep::swapOrder($step_id, $adjacent['id']);
  }
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$1");
}

// ---- Mover para baixo ------------------------------------------------------
if (isset($_POST['move_down'])) {
  $step->getFromDB($step_id);
  $adjacent = PluginEscalationStep::getAdjacentStep($step_id, 'down');
  if ($adjacent) {
    PluginEscalationStep::swapOrder($step_id, $adjacent['id']);
  }
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$1");
}

// ---- Exibir formulário de edição -------------------------------------------
Html::header(PluginEscalationStep::getTypeName(1), $_SERVER['PHP_SELF'], "config", "pluginescalationflow");
(new PluginEscalationStep())->showForm($step_id, ['withtemplate' => 0]);
Html::footer();
