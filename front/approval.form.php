<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

Session::checkLoginUser();

$ticketstep_id = (int) ($_POST['ticketstep_id'] ?? $_GET['ticketstep_id'] ?? 0);
$users_id      = (int) Session::getLoginUserID();

if (isset($_POST['approve']) || isset($_POST['reject'])) {
  // CSRF já é validado pelo GLPI (kernel no 11; includes.php no 10 para plugins
  // csrf_compliant). Uma segunda verificação aqui falha, pois o token já foi consumido.

  $action  = isset($_POST['approve']) ? PluginEscalationApproval::ACTION_APPROVE : PluginEscalationApproval::ACTION_REJECT;
  $comment = Toolbox::cleanNewLines($_POST['comment'] ?? '');

  $ok = PluginEscalationApproval::submitApproval($ticketstep_id, $users_id, $action, $comment);

  // Redirecionar de volta ao chamado
  $ts = new PluginEscalationTicketStep();
  if ($ts->getFromDB($ticketstep_id)) {
    Html::redirect(Ticket::getFormURLWithID($ts->fields['tickets_id']) . '&forcetab=PluginEscalationTicketFlow$1');
  }
  Html::back();
}

// Exibição standalone (acesso via link em notificação)
$ts = new PluginEscalationTicketStep();
if (!$ts->getFromDB($ticketstep_id)) {
  Html::displayErrorAndDie(__('Etapa de aprovação não encontrada.', 'escalation'));
}

if (!PluginEscalationApproval::isAuthorizedApprover($ticketstep_id, $users_id)) {
  Html::displayErrorAndDie(__('Você não é um aprovador desta etapa.', 'escalation'));
}

if (PluginEscalationApproval::hasUserVoted($ticketstep_id, $users_id)) {
  Html::displayErrorAndDie(__('Você já registrou seu voto nesta etapa.', 'escalation'));
}

$step = new PluginEscalationStep();
$step->getFromDB($ts->fields['plugin_escalation_steps_id']);
$ticket = new Ticket();
$ticket->getFromDB($ts->fields['tickets_id']);

Html::header(__('Aprovação de Chamado', 'escalation'), $_SERVER['PHP_SELF']);

echo "<div class='center' style='max-width:600px;margin:40px auto'>";
echo "<h2>" . __('Solicitação de Aprovação', 'escalation') . "</h2>";
echo "<p><b>" . __('Chamado', 'escalation') . ":</b> ";
echo "<a href='" . Ticket::getFormURLWithID($ticket->getID()) . "'>#" . $ticket->getID() . " — " . htmlspecialchars($ticket->fields['name']) . "</a></p>";
echo "<p><b>" . __('Etapa', 'escalation') . ":</b> " . htmlspecialchars($step->fields['name']) . "</p>";

echo "<form method='post' action='" . PluginEscalationConfig::getWebPath() . "/front/approval.form.php'>";
echo "<input type='hidden' name='ticketstep_id' value='$ticketstep_id'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<div style='margin:16px 0'>";
echo "<label><b>" . __('Comentário', 'escalation') . "</b></label><br>";
echo "<textarea name='comment' rows='4' style='width:100%;margin-top:4px'></textarea>";
echo "</div>";
echo "<button name='approve' type='submit' class='vsubmit' style='background:#22c55e;border-color:#22c55e;font-size:1rem;padding:8px 24px;margin-right:12px'>";
echo "<i class='fas fa-check'></i> " . __('Aprovar', 'escalation') . "</button>";
echo "<button name='reject' type='submit' class='vsubmit' style='background:#ef4444;border-color:#ef4444;font-size:1rem;padding:8px 24px'>";
echo "<i class='fas fa-times'></i> " . __('Reprovar', 'escalation') . "</button>";
Html::closeForm();
echo "</div>";

Html::footer();
