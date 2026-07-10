<?php
if (!defined('GLPI_ROOT')) { include('../../../inc/includes.php'); }
Session::checkRight('config', UPDATE);

$template = new PluginEscalationTemplate();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if (isset($_POST['add'])) {
  $template->check(-1, CREATE, $_POST);
  if ($new_id = $template->add([
    'name'          => $_POST['name'],
    'content'       => $_POST['content'] ?? '',
    'documents_ids' => (array) ($_POST['documents_ids'] ?? []),
  ])) {
    $modal_qs = !empty($_REQUEST['_in_modal']) ? '&_in_modal=1' : '';
    Html::redirect(PluginEscalationTemplate::getFormURL() . "?id=$new_id{$modal_qs}");
  }
  Html::back();
}

if (isset($_POST['update'])) {
  $template->check($id, UPDATE);
  $template->update([
    'id'            => $id,
    'name'          => $_POST['name'],
    'content'       => $_POST['content'] ?? '',
    'documents_ids' => (array) ($_POST['documents_ids'] ?? []),
  ]);
  Html::back();
}

if (isset($_POST['purge'])) {
  $template->check($id, PURGE);
  $template->delete(['id' => $id], 1);
  Html::redirect(PluginEscalationTemplate::getSearchURL());
}

Html::header(PluginEscalationTemplate::getTypeName(1), $_SERVER['PHP_SELF'], 'admin', 'pluginescalationflow', 'template');
if (!empty($_REQUEST['_in_modal'])) {
  $template->getFromDB($id);
  $template->showForm($id);
} else {
  $template->display(['id' => $id]);
}
Html::footer();
