<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$action = $_POST['_action'] ?? '';

// ---- Salvar configurações gerais ------------------------------------------
if ($action === 'save_config' && isset($_POST['update'])) {
  Config::setConfigurationValues('plugin_escalation', [
    'hierarchy_type' => $_POST['hierarchy_type'] ?? 'Group',
  ]);
  Session::addMessageAfterRedirect(__('Configurações salvas.', 'escalation'));
  Html::back();
}

// ---- Adicionar nível de hierarquia ----------------------------------------
if ($action === 'add_level') {
  $level    = (int) ($_POST['new_level']    ?? 0);
  $items_id = (int) ($_POST['new_items_id'] ?? 0);
  $itemtype = $_POST['new_itemtype'] ?? 'Group';

  if ($level > 0 && $items_id > 0 && in_array($itemtype, ['Group', 'UserCategory'])) {
    $obj = new PluginEscalationHierarchyLevel();
    $obj->add([
      'level'    => $level,
      'items_id' => $items_id,
      'itemtype' => $itemtype,
    ]);
    Session::addMessageAfterRedirect(
      sprintf(__('Nível %d adicionado.', 'escalation'), $level)
    );
  } else {
    Session::addMessageAfterRedirect(
      __('Preencha o nível e selecione um grupo/categoria.', 'escalation'),
      false,
      ERROR
    );
  }
  Html::back();
}

// ---- Remover nível de hierarquia ------------------------------------------
if ($action === 'delete_level') {
  $level_id = (int) ($_POST['level_id'] ?? 0);
  if ($level_id > 0) {
    $obj = new PluginEscalationHierarchyLevel();
    $obj->delete(['id' => $level_id], 1);
    Session::addMessageAfterRedirect(__('Nível removido.', 'escalation'));
  }
  Html::back();
}

Html::back();
