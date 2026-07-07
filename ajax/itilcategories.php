<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

global $DB;

$iter = $DB->request([
  'FROM'    => 'glpi_itilcategories',
  'ORDERBY' => ['completename ASC'],
]);

$categories = [];
foreach ($iter as $row) {
  $categories[] = ['id' => (int)$row['id'], 'text' => $row['completename']];
}

echo json_encode($categories);
