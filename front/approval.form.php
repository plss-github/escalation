<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkLoginUser();

// A aprovação pela aba do plugin foi descontinuada: ela concluía o fluxo sem
// atualizar as validações nativas do GLPI. As aprovações são feitas somente
// pelas validações nativas (timeline / aba de aprovações do chamado).
// Mantido apenas para redirecionar links antigos para o chamado.
$ticketstep_id = (int) ($_POST['ticketstep_id'] ?? $_GET['ticketstep_id'] ?? 0);

$ts = new PluginEscalationTicketStep();
if ($ticketstep_id > 0 && $ts->getFromDB($ticketstep_id)) {
  Html::redirect(Ticket::getFormURLWithID($ts->fields['tickets_id']));
}
Html::back();
