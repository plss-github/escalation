<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Registro de aprovação/reprovação individual de um usuário em uma etapa.
 */
class PluginEscalationApproval extends CommonDBTM {
  static $rightname = 'config';

  const ACTION_APPROVE = 1;
  const ACTION_REJECT  = 2;

  static function getTypeName($nb = 0) {
    return _n('Aprovação', 'Aprovações', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_ticketsteps_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `action` tinyint(1) NOT NULL DEFAULT '1',
        `comment` text COLLATE {$default_collation},
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `plugin_escalation_ticketsteps_id` (`plugin_escalation_ticketsteps_id`),
        KEY `users_id` (`users_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return true; } // aprovadores não precisam ser admins
  static function canView(): bool   { return true; }

  // -------------------------------------------------------------------------
  // API pública
  // -------------------------------------------------------------------------

  /**
   * Registra um voto e decide se a etapa foi concluída.
   * Retorna false se o voto não for válido (não autorizado, já votou, etapa inativa).
   */
  static function submitApproval(int $ticketstep_id, int $users_id, int $action, string $comment): bool {
    global $DB;

    // Validações
    $ts = new PluginEscalationTicketStep();
    if (!$ts->getFromDB($ticketstep_id)) return false;
    if ($ts->fields['status'] !== PluginEscalationTicketStep::STATUS_PENDING) return false;
    if (!self::isAuthorizedApprover($ticketstep_id, $users_id)) return false;
    if (self::hasUserVoted($ticketstep_id, $users_id)) return false;

    // Registrar voto
    $approval = new self();
    $approval->add([
      'plugin_escalation_ticketsteps_id' => $ticketstep_id,
      'users_id'      => $users_id,
      'action'        => $action,
      'comment'       => Toolbox::cleanNewLines($comment),
      'date_creation' => $_SESSION['glpi_currenttime'],
    ]);

    // Buscar definição da etapa para saber quórum
    $step = new PluginEscalationStep();
    if (!$step->getFromDB($ts->fields['plugin_escalation_steps_id'])) return false;

    if ($step->fields['type'] === PluginEscalationStep::TYPE_HIERARCHY) {
      // TODO: lógica de hierarquia
      return true;
    }

    // Avaliar quórum
    $total     = self::countApprovers($ticketstep_id);
    $approvals = self::countVotes($ticketstep_id, self::ACTION_APPROVE);
    $rejections = self::countVotes($ticketstep_id, self::ACTION_REJECT);
    $result = self::evaluateQuorum($step->fields['quorum_type'], $total, $approvals, $rejections);

    if ($result === 'approved') {
      $ts->update([
        'id'           => $ticketstep_id,
        'status'       => PluginEscalationTicketStep::STATUS_APPROVED,
        'completed_at' => $_SESSION['glpi_currenttime'],
      ]);
      PluginEscalationTicketFlow::advanceFlow($ts->fields['plugin_escalation_ticketflows_id']);

    } elseif ($result === 'rejected') {
      $ts->update([
        'id'           => $ticketstep_id,
        'status'       => PluginEscalationTicketStep::STATUS_REJECTED,
        'completed_at' => $_SESSION['glpi_currenttime'],
      ]);
      PluginEscalationTicketFlow::rejectFlow($ts->fields['plugin_escalation_ticketflows_id']);
    }

    return true;
  }

  /**
   * Avalia o quórum com base nos votos atuais.
   * Retorna 'approved', 'rejected' ou 'pending'.
   */
  static function evaluateQuorum(string $quorum_type, int $total, int $approvals, int $rejections): string {
    if ($total === 0) return 'pending';

    switch ($quorum_type) {
      case PluginEscalationStep::QUORUM_MIN1:
        if ($approvals >= 1) return 'approved';
        if ($rejections >= $total) return 'rejected'; // todos rejeitaram
        return 'pending';

      case PluginEscalationStep::QUORUM_MAJORITY:
        $majority = (int) ceil($total / 2);
        if ($approvals >= $majority) return 'approved';
        if ($rejections >= $majority) return 'rejected';
        return 'pending';

      case PluginEscalationStep::QUORUM_ALL:
        if ($rejections >= 1) return 'rejected'; // qualquer rejeição encerra
        if ($approvals >= $total) return 'approved';
        return 'pending';
    }

    return 'pending';
  }

  // -------------------------------------------------------------------------
  // Helpers de consulta
  // -------------------------------------------------------------------------

  static function isAuthorizedApprover(int $ticketstep_id, int $users_id): bool {
    global $DB;
    $row = $DB->request([
      'FROM'  => 'glpi_plugin_escalation_ticketstepapprovers',
      'WHERE' => ['plugin_escalation_ticketsteps_id' => $ticketstep_id, 'users_id' => $users_id],
    ]);
    return count($row) > 0;
  }

  static function hasUserVoted(int $ticketstep_id, int $users_id): bool {
    global $DB;
    $row = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['plugin_escalation_ticketsteps_id' => $ticketstep_id, 'users_id' => $users_id],
    ]);
    return count($row) > 0;
  }

  static function countApprovers(int $ticketstep_id): int {
    global $DB;
    return count($DB->request([
      'FROM'  => 'glpi_plugin_escalation_ticketstepapprovers',
      'WHERE' => ['plugin_escalation_ticketsteps_id' => $ticketstep_id],
    ]));
  }

  static function countVotes(int $ticketstep_id, int $action): int {
    global $DB;
    return count($DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['plugin_escalation_ticketsteps_id' => $ticketstep_id, 'action' => $action],
    ]));
  }

  static function getVotesForStep(int $ticketstep_id): array {
    global $DB;
    return iterator_to_array($DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_escalation_ticketsteps_id' => $ticketstep_id],
      'ORDERBY' => ['date_creation ASC'],
    ]));
  }
}
