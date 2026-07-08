<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

global $DB;

$rows = $DB->request([
  'SELECT'  => ['id', 'name'],
  'FROM'    => PluginEscalationTemplate::getTable(),
  'ORDERBY' => ['name ASC'],
]);

$result = [];
foreach ($rows as $row) {
  $result[] = ['id' => $row['id'], 'text' => $row['name']];
}

header('Content-Type: application/json');
echo json_encode($result);
