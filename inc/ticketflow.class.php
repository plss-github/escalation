<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Acompanha o estado de um fluxo de aprovação em andamento para um chamado.
 * Implementa a aba "Aprovação" dentro da tela do chamado.
 */
class PluginEscalationTicketFlow extends CommonDBTM {
  static $rightname = 'config';

  static function addDebugFollowup(int $ticket_id, string $content): void {
    if (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
      $fup = new ITILFollowup();
      $fup->add(['itemtype' => 'Ticket', 'items_id' => $ticket_id, 'content' => $content, 'is_private' => 1]);
    }
  }

  private static function isGlpi10(): bool {
    return version_compare(GLPI_VERSION, '11', '<');
  }

  const STATUS_PENDING    = 0;
  const STATUS_APPROVED   = 1;
  const STATUS_REJECTED   = 2;
  const STATUS_TIMED_OUT  = 3;

  static function getTypeName($nb = 0) {
    return _n('Fluxo do Chamado', 'Fluxos do Chamado', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_escalation_flows_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `status` tinyint(1) NOT NULL DEFAULT '0',
        `current_step` int NOT NULL DEFAULT '1',
        `started_at` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `tickets_id` (`tickets_id`),
        KEY `status` (`status`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return true; } // qualquer usuário logado pode ver o status
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }

  // -------------------------------------------------------------------------
  // Aba no chamado
  // -------------------------------------------------------------------------

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item->getType() === 'Ticket') {
      $count = self::countActiveFlows($item->getID());
      return [1 => self::createTabEntry(__('Aprovação', 'escalation'), $count, null, 'ti ti-sitemap')];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === 'Ticket' && $tabnum == 1) {
      self::showApprovalTab($item);
    }
    return true;
  }

  static function countActiveFlows(int $ticket_id): int {
    global $DB;
    return count($DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket_id],
    ]));
  }

  // -------------------------------------------------------------------------
  // Lógica do fluxo
  // -------------------------------------------------------------------------

  /**
   * Inicia um fluxo de aprovação para um chamado.
   * Chamado por PluginEscalationFlow::ticketAdd.
   */
  static function startFlow(Ticket $ticket, int $flow_id): void {
    global $DB;

    $ticket_id = $ticket->getID();

    // Verificar se já existe um fluxo ativo para este flow_id neste chamado
    $existing = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket_id, 'plugin_escalation_flows_id' => $flow_id],
    ]);
    if (count($existing) > 0) return;

    // Verificar se o fluxo tem etapas
    $steps = $DB->request([
      'FROM'    => PluginEscalationStep::getTable(),
      'WHERE'   => ['plugin_escalation_flows_id' => $flow_id],
      'ORDERBY' => ['step_order ASC'],
    ]);
    $steps_array = array_values(iterator_to_array($steps));
    if (empty($steps_array)) return;

    // Criar registro do fluxo no chamado
    $tf = new self();
    $tf_id = $tf->add([
      'tickets_id'                  => $ticket_id,
      'plugin_escalation_flows_id'  => $flow_id,
      'status'                      => self::STATUS_PENDING,
      'current_step'                => 1,
      'started_at'                  => $_SESSION['glpi_currenttime'],
    ]);
    if (!$tf_id) return;

    // Criar registros de ticket_step para todas as etapas (aguardando)
    foreach ($steps_array as $i => $step_data) {
      $ts = new PluginEscalationTicketStep();
      $ts->add([
        'tickets_id'                      => $ticket_id,
        'plugin_escalation_ticketflows_id' => $tf_id,
        'plugin_escalation_steps_id'      => $step_data['id'],
        'status'                          => ($i === 0)
          ? PluginEscalationTicketStep::STATUS_PENDING
          : PluginEscalationTicketStep::STATUS_WAITING,
        'started_at'                      => ($i === 0) ? $_SESSION['glpi_currenttime'] : null,
      ]);
    }

    // Ativar primeira etapa: resolver aprovadores e notificar
    $first_step_data = $steps_array[0];
    $first_ts = self::getTicketStepByOrder($tf_id, 1);
    if ($first_ts) {
      self::activateStep($first_ts['id'], $ticket, $first_step_data);
    }
  }

  /**
   * Avança para a próxima etapa ou conclui o fluxo como aprovado.
   * Chamado por PluginEscalationApproval::submitApproval quando uma etapa é aprovada.
   */
  static function advanceFlow(int $ticket_flow_id): void {
    global $DB;

    $tf = new self();
    if (!$tf->getFromDB($ticket_flow_id) || $tf->fields['status'] !== self::STATUS_PENDING) return;

    $next_step = $tf->fields['current_step'] + 1;

    // Buscar próxima etapa de ticket_step (WAITING → PENDING)
    $next_ts = self::getTicketStepByOrder($ticket_flow_id, $next_step);

    if (!$next_ts) {
      // Todas as etapas concluídas: fluxo aprovado
      self::completeFlow($ticket_flow_id, self::STATUS_APPROVED);
      return;
    }

    // Atualizar current_step
    $tf->update(['id' => $ticket_flow_id, 'current_step' => $next_step]);

    // Ativar próxima etapa
    $step_def = new PluginEscalationStep();
    $step_def->getFromDB($next_ts['plugin_escalation_steps_id']);
    $ticket = new Ticket();
    $ticket->getFromDB($tf->fields['tickets_id']);
    self::activateStep($next_ts['id'], $ticket, $step_def->fields);
  }

  /**
   * Rejeita o fluxo inteiro.
   * Chamado por PluginEscalationApproval::submitApproval quando uma etapa é rejeitada.
   */
  static function rejectFlow(int $ticket_flow_id): void {
    self::completeFlow($ticket_flow_id, self::STATUS_REJECTED);
  }

  static function completeFlow(int $ticket_flow_id, int $final_status): void {
    global $DB;

    $tf = new self();
    if (!$tf->getFromDB($ticket_flow_id)) return;

    $tf->update(['id' => $ticket_flow_id, 'status' => $final_status]);

    $ticket = new Ticket();
    if (!$ticket->getFromDB($tf->fields['tickets_id'])) return;

    $flow = new PluginEscalationFlow();
    $flow_name = $flow->getFromDB($tf->fields['plugin_escalation_flows_id'])
      ? $flow->fields['name']
      : '#' . $tf->fields['plugin_escalation_flows_id'];

    switch ($final_status) {
      case self::STATUS_APPROVED:
        $msg = sprintf(__('✅ Fluxo de aprovação "%s" concluído. Chamado aprovado.', 'escalation'), $flow_name);
        break;
      case self::STATUS_TIMED_OUT:
        $msg = sprintf(__('⏰ Fluxo de aprovação "%s" atingiu o prazo de reprovação automática. Chamado reprovado automaticamente.', 'escalation'), $flow_name);
        break;
      default:
        $msg = sprintf(__('❌ Fluxo de aprovação "%s" concluído. Chamado reprovado.', 'escalation'), $flow_name);
    }

    $fup = new ITILFollowup();
    $fup->add(['itemtype' => 'Ticket', 'items_id' => $tf->fields['tickets_id'], 'content' => $msg, 'is_private' => 0]);
  }

  // -------------------------------------------------------------------------
  // Helpers internos
  // -------------------------------------------------------------------------

  static function getTicketStepByOrder(int $ticket_flow_id, int $order): ?array {
    global $DB;

    // Busca o ticket_step correspondente à N-ésima etapa (em step_order)
    $row = $DB->request([
      'SELECT' => ['ts.*'],
      'FROM'   => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        PluginEscalationStep::getTable() . ' AS s' => [
          'ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']
        ]
      ],
      'WHERE'   => ['ts.plugin_escalation_ticketflows_id' => $ticket_flow_id, 's.step_order' => $order],
      'LIMIT'   => 1,
    ])->current();

    return $row ?: null;
  }

  static function activateStep(int $ticketstep_id, Ticket $ticket, array $step_data): void {
    global $DB;

    $DB->update(PluginEscalationTicketStep::getTable(), [
      'status'     => PluginEscalationTicketStep::STATUS_PENDING,
      'started_at' => $_SESSION['glpi_currenttime'],
    ], ['id' => $ticketstep_id]);

    if (empty($step_data['generate_immediately'])) {
      self::addDebugFollowup($ticket->getID(),
        sprintf(__('⏳ Etapa "%s" aguardando submissão de solução do chamado.', 'escalation'), $step_data['name'] ?? '')
      );
      return;
    }

    self::generateValidationsForStep($ticketstep_id, $ticket, $step_data);
  }

  /**
   * Resolve aprovadores e cria TicketValidation para a etapa.
   * Usado tanto em ativação imediata quanto após submissão de solução.
   */
  static function generateValidationsForStep(int $ticketstep_id, Ticket $ticket, array $step_data): void {
    global $DB;

    $step_name = $step_data['name'] ?? '';
    $step_type = $step_data['type'] ?? PluginEscalationStep::TYPE_PEOPLE;
    $approvers = [];
    $skip      = false;

    if ($step_type === PluginEscalationStep::TYPE_HIERARCHY) {
      if (!empty($step_data['auto_hierarchy_dynamic'])) {
        // Dinâmico: parte do requerente, sobe via users_id_supervisor
        $requesters = self::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER);
        foreach ($requesters as $req_id) {
          $req_level = PluginEscalationHierarchyLevel::getUserLevel((int) $req_id);
          if ($req_level !== null && $req_level > 1) {
            $found = self::traverseHierarchy((int) $req_id, $req_level - 1);
            if ($found !== null && $found !== (int) $req_id) {
              $approvers[] = $found;
            }
          }
        }
          if (empty($approvers)) $skip = true;
      } else {
        // Nível fixo: parte do requerente e sobe via supervisor até o nível alvo
        $target_level = (int) ($step_data['hierarchy_level'] ?? 1);
        $requesters   = self::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER);
        foreach ($requesters as $req_id) {
          $found = self::traverseHierarchy((int) $req_id, $target_level);
          if ($found !== null) {
            $approvers[] = $found;
          }
        }
        $approvers = array_values(array_unique(array_filter($approvers)));
        if (empty($approvers)) $skip = true;
      }
    } else {
      // PEOPLE: fontes configuradas (usuários específicos, solicitantes, responsáveis, observadores)
      $approvers = self::resolveApprovers($step_data, $ticket);
    }

    // Criar TicketValidation e anexar documentos do modelo
    if (!$skip && !empty($approvers)) {
      $submission_content = trim($step_data['content'] ?? '');
      $tmpl_doc_ids       = [];
      $tmpl_id = (int) ($step_data['plugin_escalation_templates_id'] ?? 0);
      if ($tmpl_id > 0) {
        $tmpl = new PluginEscalationTemplate();
        if ($tmpl->getFromDB($tmpl_id)) {
          // Template tem prioridade no conteúdo caso o step não tenha texto próprio
          if (empty($submission_content)) {
            $submission_content = trim($tmpl->fields['content'] ?? '');
          }
          $tmpl_doc_ids = PluginEscalationTemplate::getDocuments($tmpl_id);
        }
      }
      $glpi10 = self::isGlpi10();
      foreach ($approvers as $user_id) {
        $validation = new TicketValidation();
        $val_data = ['tickets_id' => $ticket->getID(), 'comment_submission' => $submission_content];
        if ($glpi10) {
          $val_data['users_id_validate'] = (int) $user_id;
        } else {
          $val_data['itemtype_target'] = 'User';
          $val_data['items_id_target'] = (int) $user_id;
        }
        $validation->add($val_data);
        // Registra o aprovador na etapa para controle de quórum
        $DB->doQuery(sprintf(
          "INSERT IGNORE INTO `glpi_plugin_escalation_ticketstepapprovers`
           (`plugin_escalation_ticketsteps_id`, `users_id`) VALUES (%d, %d)",
          (int) $ticketstep_id, (int) $user_id
        ));
      }
      foreach ($tmpl_doc_ids as $doc_id) {
        $doc_item = new Document_Item();
        $doc_item->add([
          'itemtype'         => 'Ticket',
          'items_id'         => $ticket->getID(),
          'documents_id'     => (int) $doc_id,
          '_no_message_link' => true,
        ]);
      }
    }

    // Auto-avanço quando não há aprovadores
    if ($skip || empty($approvers)) {
      $content = $skip
        ? sprintf(__('⚡ Etapa "%s" ignorada — sem aprovador hierárquico definido.', 'escalation'), $step_name)
        : sprintf(__('⚡ Etapa "%s" aprovada automaticamente (sem aprovadores configurados).', 'escalation'), $step_name);
      self::addDebugFollowup($ticket->getID(), $content);

      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status'       => PluginEscalationTicketStep::STATUS_APPROVED,
        'completed_at' => $_SESSION['glpi_currenttime'],
      ], ['id' => $ticketstep_id]);
      $ts = new PluginEscalationTicketStep();
      $ts->getFromDB($ticketstep_id);
      self::advanceFlow($ts->fields['plugin_escalation_ticketflows_id']);
    } else {
      if (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
        $user_names = [];
        foreach ($approvers as $uid) {
          $u = new User();
          if ($u->getFromDB($uid)) $user_names[] = $u->getFriendlyName();
        }
        self::addDebugFollowup($ticket->getID(), sprintf(
          __('⚡ Etapa "%s": aprovação gerada. Aguardando validação de: %s.', 'escalation'),
          $step_name, implode(', ', $user_names)
        ));
      }
    }
  }

  /**
   * Chamado quando o chamado vai para solução (status SOLVED).
   * Ativa etapas com generate_immediately = 0 que estão aguardando.
   */
  static function ticketSolved(Ticket $ticket): void {
    global $DB;

    $tf_row = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket->getID(), 'status' => self::STATUS_PENDING],
      'LIMIT' => 1,
    ])->current();
    if (!$tf_row) return;

    $current_ts = self::getTicketStepByOrder($tf_row['id'], $tf_row['current_step']);
    if (!$current_ts || $current_ts['status'] !== PluginEscalationTicketStep::STATUS_PENDING) return;

    $step_def = new PluginEscalationStep();
    if (!$step_def->getFromDB($current_ts['plugin_escalation_steps_id'])) return;

    // Só age se este passo estava aguardando solução (generate_immediately = 0)
    if (!empty($step_def->fields['generate_immediately'])) return;

    self::generateValidationsForStep($current_ts['id'], $ticket, $step_def->fields);
  }

  /**
   * Chamado via hook item_update quando um TicketValidation é atualizado.
   * Se o chamado tem um fluxo ativo cuja etapa atual é AUTO, avança ou rejeita conforme o resultado.
   */
  static function validationUpdated(CommonDBTM $item): void {
    global $DB;

    if (!$item instanceof TicketValidation) return;

    $status    = (int) $item->fields['status'];
    $accepted  = CommonITILValidation::ACCEPTED;
    $refused   = CommonITILValidation::REFUSED;
    $waiting   = CommonITILValidation::WAITING;
    if (!in_array($status, [$accepted, $refused])) return;

    $ticket_id = (int) $item->fields['tickets_id'];

    // Fluxo ativo para este chamado
    $tf_row = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket_id, 'status' => self::STATUS_PENDING],
      'LIMIT' => 1,
    ])->current();
    if (!$tf_row) return;

    // Etapa atual em andamento
    $current_ts = self::getTicketStepByOrder($tf_row['id'], $tf_row['current_step']);
    if (!$current_ts || $current_ts['status'] !== PluginEscalationTicketStep::STATUS_PENDING) return;

    // Verificar se a etapa atual usa TicketValidations (generate_immediately ou após solução)
    $step_def = new PluginEscalationStep();
    if (!$step_def->getFromDB($current_ts['plugin_escalation_steps_id'])) return;

    if ($status === $refused) {
      // Qualquer recusa rejeita o fluxo
      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status'       => PluginEscalationTicketStep::STATUS_REJECTED,
        'completed_at' => $_SESSION['glpi_currenttime'],
      ], ['id' => $current_ts['id']]);
      self::completeFlow($tf_row['id'], self::STATUS_REJECTED);
      return;
    }

    // Carregar aprovadores registrados para esta etapa
    $approver_rows = $DB->request([
      'SELECT' => ['users_id'],
      'FROM'   => 'glpi_plugin_escalation_ticketstepapprovers',
      'WHERE'  => ['plugin_escalation_ticketsteps_id' => $current_ts['id']],
    ]);
    $step_approver_ids = array_column(iterator_to_array($approver_rows), 'users_id');

    $glpi10 = self::isGlpi10();

    // Sem registro de aprovadores → fallback: aguardar pending=0 no chamado (comportamento anterior)
    if (empty($step_approver_ids)) {
      $pending = count($DB->request([
        'FROM'  => TicketValidation::getTable(),
        'WHERE' => ['tickets_id' => $ticket_id, 'status' => $waiting],
      ]));
      if ($pending > 0) return;
    } else {
      // Contar quantos aprovadores desta etapa já aprovaram
      $approved_where = ['tickets_id' => $ticket_id, 'status' => $accepted];
      if ($glpi10) {
        $approved_where['users_id_validate'] = $step_approver_ids;
      } else {
        $approved_where['itemtype_target'] = 'User';
        $approved_where['items_id_target'] = $step_approver_ids;
      }
      $approved_count = count($DB->request(['FROM' => TicketValidation::getTable(), 'WHERE' => $approved_where]));
      $total = count($step_approver_ids);

      $quorum = $step_def->fields['quorum_type'] ?? PluginEscalationStep::QUORUM_ALL;
      // Hierarquia sempre usa all; people respeita o quórum configurado
      if ($step_def->fields['type'] === PluginEscalationStep::TYPE_HIERARCHY) {
        $quorum = PluginEscalationStep::QUORUM_ALL;
      }

      if ($quorum === PluginEscalationStep::QUORUM_MIN1) {
        $reached = $approved_count >= 1;
      } elseif ($quorum === PluginEscalationStep::QUORUM_MAJORITY) {
        $reached = $approved_count > ($total / 2);
      } else {
        $reached = $approved_count >= $total;
      }

      if (!$reached) return;

      // Quórum atingido: cancelar validações ainda pendentes dos demais aprovadores
      if ($quorum !== PluginEscalationStep::QUORUM_ALL) {
        $pending_where = ['tickets_id' => $ticket_id, 'status' => $waiting];
        if ($glpi10) {
          $pending_where['users_id_validate'] = $step_approver_ids;
        } else {
          $pending_where['itemtype_target'] = 'User';
          $pending_where['items_id_target'] = $step_approver_ids;
        }
        $DB->update(TicketValidation::getTable(), ['status' => CommonITILValidation::ACCEPTED], $pending_where);
      }
    }

    // Etapa aprovada pelo quórum
    $DB->update(PluginEscalationTicketStep::getTable(), [
      'status'       => PluginEscalationTicketStep::STATUS_APPROVED,
      'completed_at' => $_SESSION['glpi_currenttime'],
    ], ['id' => $current_ts['id']]);
    self::advanceFlow($tf_row['id']);
  }

  static function resolveApprovers(array $step_data, Ticket $ticket): array {
    global $DB;

    $user_ids = [];

    // Usuários específicos da definição da etapa
    $specific = PluginEscalationStepUser::getUsersForStep($step_data['id']);
    foreach ($specific as $su) {
      $user_ids[] = (int) $su['users_id'];
    }

    $ticket_id = $ticket->getID();

    // Solicitantes (type = 1)
    if (!empty($step_data['include_requesters'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::REQUESTER) as $uid) {
        $user_ids[] = $uid;
      }
    }

    // Responsáveis (type = 2)
    if (!empty($step_data['include_assigned'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::ASSIGN) as $uid) {
        $user_ids[] = $uid;
      }
    }

    // Observadores (type = 3)
    if (!empty($step_data['include_observers'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::OBSERVER) as $uid) {
        $user_ids[] = $uid;
      }
    }

    return array_values(array_unique(array_filter($user_ids)));
  }

  static function getTicketActors(int $ticket_id, int $actor_type): array {
    global $DB;

    $rows = $DB->request([
      'SELECT' => ['users_id'],
      'FROM'   => 'glpi_tickets_users',
      'WHERE'  => ['tickets_id' => $ticket_id, 'type' => $actor_type, ['users_id' => ['>', 0]]],
    ]);
    return array_column(iterator_to_array($rows), 'users_id');
  }

  // -------------------------------------------------------------------------
  // Resolução de aprovadores por hierarquia
  // -------------------------------------------------------------------------

  /**
   * Encontra aprovadores para uma etapa de hierarquia.
   *
   * Para cada técnico responsável pelo chamado, sobe a cadeia de supervisores
   * até encontrar alguém cujo nível hierárquico seja <= $required_level.
   */
  static function resolveHierarchyApprovers(Ticket $ticket, int $required_level): array {
    $assigned = self::getTicketActors($ticket->getID(), CommonITILActor::ASSIGN);
    $approvers = [];
    foreach ($assigned as $user_id) {
      $found = self::traverseHierarchy((int) $user_id, $required_level);
      if ($found !== null) {
        $approvers[] = $found;
      }
    }
    return array_values(array_unique(array_filter($approvers)));
  }

  /**
   * Sobe a cadeia de supervisores a partir de $user_id até encontrar
   * alguém com nível hierárquico <= $required_level.
   *
   * Retorna o users_id do aprovador encontrado, ou null se não houver.
   */
  static function traverseHierarchy(int $user_id, int $required_level, int $depth = 0): ?int {
    if ($depth > 20 || $user_id <= 0) return null;

    $level = PluginEscalationHierarchyLevel::getUserLevel($user_id);
    if ($level !== null && $level <= $required_level) {
      return $user_id;
    }

    $user = new User();
    if (!$user->getFromDB($user_id)) return null;

    $supervisor_id = (int) ($user->fields['users_id_supervisor'] ?? 0);
    if ($supervisor_id <= 0 || $supervisor_id === $user_id) return null;

    return self::traverseHierarchy($supervisor_id, $required_level, $depth + 1);
  }

  // -------------------------------------------------------------------------
  // UI da aba "Aprovação" no chamado
  // -------------------------------------------------------------------------

  static function showApprovalTab(Ticket $ticket): void {
    global $DB;

    $ticket_id = $ticket->getID();

    $flows = $DB->request([
      'SELECT' => ['tf.*', 'f.name AS flow_name', 'f.timeout_days AS flow_timeout'],
      'FROM'   => self::getTable() . ' AS tf',
      'INNER JOIN' => [
        PluginEscalationFlow::getTable() . ' AS f' => ['ON' => ['tf' => 'plugin_escalation_flows_id', 'f' => 'id']]
      ],
      'WHERE'  => ['tf.tickets_id' => $ticket_id],
    ]);

    $flows_array = iterator_to_array($flows);

    if (empty($flows_array)) {
      echo "<p class='center'>" . __('Nenhum fluxo de aprovação ativo para este chamado.', 'escalation') . "</p>";
      return;
    }

    foreach ($flows_array as $tf_data) {
      $tf_id     = $tf_data['id'];
      $status    = $tf_data['status'];
      $flow_name = $tf_data['flow_name'];

      echo "<div class='spaced'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='headerRow'><th colspan='4'>" . htmlspecialchars($flow_name) . " — " . self::getStatusLabel($status) . "</th></tr>";

      // Etapas
      $steps_data = $DB->request([
        'SELECT' => ['ts.*', 's.name AS step_name', 's.type', 's.quorum_type', 's.step_order'],
        'FROM'   => PluginEscalationTicketStep::getTable() . ' AS ts',
        'INNER JOIN' => [
          PluginEscalationStep::getTable() . ' AS s' => ['ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']]
        ],
        'WHERE'   => ['ts.plugin_escalation_ticketflows_id' => $tf_id],
        'ORDERBY' => ['s.step_order ASC'],
      ]);

      $current_user_id = Session::getLoginUserID();

      foreach ($steps_data as $i => $ts) {
        $ts_status = $ts['status'];
        echo "<tr class='tab_bg_" . (($i % 2) + 1) . "'>";
        echo "<td><b>#" . ($i + 1) . " " . htmlspecialchars($ts['step_name']) . "</b></td>";
        echo "<td>" . PluginEscalationTicketStep::getStatusLabel($ts_status) . "</td>";
        echo "<td>" . PluginEscalationStep::getTypeName_($ts['type']);
        if ($ts['type'] === PluginEscalationStep::TYPE_PEOPLE) {
          echo " / " . PluginEscalationStep::getQuorumName($ts['quorum_type']);
        } elseif ($ts['type'] === PluginEscalationStep::TYPE_AUTO) {
          echo " — " . __('Auto-executado', 'escalation');
        }
        echo "</td>";
        echo "<td>";

        // Mostrar votações existentes
        $votes = PluginEscalationApproval::getVotesForStep($ts['id']);
        foreach ($votes as $v) {
          $u = new User();
          $uname = $u->getFromDB($v['users_id']) ? $u->getFriendlyName() : '#' . $v['users_id'];
          $icon  = $v['action'] == PluginEscalationApproval::ACTION_APPROVE ? '✅' : '❌';
          echo "<small>$icon " . htmlspecialchars($uname) . "</small><br>";
        }

        // Formulário de aprovação para o usuário atual (se for aprovador e etapa ativa)
        if ($ts_status === PluginEscalationTicketStep::STATUS_PENDING
          && PluginEscalationApproval::isAuthorizedApprover($ts['id'], $current_user_id)
          && !PluginEscalationApproval::hasUserVoted($ts['id'], $current_user_id)
        ) {
          echo "<form method='post' action='" . Plugin::getWebDir('escalation') . "/front/approval.form.php'>";
          echo "<input type='hidden' name='ticketstep_id' value='" . $ts['id'] . "'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo "<textarea name='comment' rows='2' style='width:100%;margin:4px 0' placeholder='" . __('Comentário (opcional)', 'escalation') . "'></textarea>";
          echo "<button name='approve' type='submit' class='vsubmit btn-sm' style='background:#22c55e;border-color:#22c55e;gap:4px'><i class='fas fa-check'></i> " . __('Aprovar', 'escalation') . "</button>&nbsp;";
          echo "<button name='reject' type='submit' class='vsubmit btn-sm' style='background:#ef4444;border-color:#ef4444;gap:4px'><i class='fas fa-times'></i> " . __('Reprovar', 'escalation') . "</button>";
          Html::closeForm();
        }

        echo "</td></tr>";
      }

      echo "</table></div>";
    }
  }

  // -------------------------------------------------------------------------
  // CronTask: verificação de timeouts
  // -------------------------------------------------------------------------

  /**
   * Metadados da tarefa expostos na interface de Ações Automáticas do GLPI.
   */
  static function cronInfo(string $name): array {
    switch ($name) {
      case 'checktimeouts':
        return ['description' => __('Verifica e reprova automaticamente etapas/fluxos de aprovação que ultrapassaram o prazo configurado.', 'escalation')];
    }
    return [];
  }

  /**
   * Executa a verificação de timeouts.
   * Retorno: 1 = processou itens, 0 = nada a fazer, -1 = erro.
   */
  static function cronChecktimeouts(CronTask $task): int {
    global $DB;

    $now     = time();
    $volume  = 0;

    // ------------------------------------------------------------------
    // 1. Timeouts de etapas individuais
    // ------------------------------------------------------------------
    $step_iterator = $DB->request([
      'SELECT'     => [
        'ts.id',
        'ts.plugin_escalation_ticketflows_id',
        'ts.started_at',
        's.timeout_days',
        's.name AS step_name',
      ],
      'FROM'       => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        PluginEscalationStep::getTable() . ' AS s' => [
          'ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id'],
        ],
      ],
      'WHERE'      => [
        'ts.status' => PluginEscalationTicketStep::STATUS_PENDING,
        ['s.timeout_days' => ['>', 0]],
        ['ts.started_at'   => ['!=', null]],
      ],
    ]);

    foreach ($step_iterator as $row) {
      $expire_at = strtotime($row['started_at']) + ((int) $row['timeout_days'] * 86400);
      if ($expire_at > $now) continue;

      // Marcar etapa como expirada
      $ts_obj = new PluginEscalationTicketStep();
      $ts_obj->update([
        'id'           => $row['id'],
        'status'       => PluginEscalationTicketStep::STATUS_TIMED_OUT,
        'completed_at' => date('Y-m-d H:i:s'),
      ]);

      // Encerrar o fluxo como reprovação automática
      self::completeFlow((int) $row['plugin_escalation_ticketflows_id'], self::STATUS_TIMED_OUT);

      $task->log(sprintf(
        __('Etapa "%s" (ID %d) sofreu reprovação automática após %d dia(s).', 'escalation'),
        $row['step_name'],
        $row['id'],
        $row['timeout_days']
      ));

      $volume++;
    }

    // ------------------------------------------------------------------
    // 2. Timeout global do fluxo (para fluxos ainda pendentes após o passo 1)
    // ------------------------------------------------------------------
    $flow_iterator = $DB->request([
      'SELECT'     => [
        'tf.id',
        'tf.started_at',
        'f.timeout_days',
        'f.name AS flow_name',
      ],
      'FROM'       => self::getTable() . ' AS tf',
      'INNER JOIN' => [
        PluginEscalationFlow::getTable() . ' AS f' => [
          'ON' => ['tf' => 'plugin_escalation_flows_id', 'f' => 'id'],
        ],
      ],
      'WHERE'      => [
        'tf.status'       => self::STATUS_PENDING,
        ['f.timeout_days' => ['>', 0]],
        ['tf.started_at'   => ['!=', null]],
      ],
    ]);

    foreach ($flow_iterator as $row) {
      $expire_at = strtotime($row['started_at']) + ((int) $row['timeout_days'] * 86400);
      if ($expire_at > $now) continue;

      // Marcar etapas WAITING/PENDING como expiradas antes de fechar o fluxo
      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status'       => PluginEscalationTicketStep::STATUS_TIMED_OUT,
        'completed_at' => date('Y-m-d H:i:s'),
      ], [
        'plugin_escalation_ticketflows_id' => $row['id'],
        'status' => [
          PluginEscalationTicketStep::STATUS_WAITING,
          PluginEscalationTicketStep::STATUS_PENDING,
        ],
      ]);

      self::completeFlow((int) $row['id'], self::STATUS_TIMED_OUT);

      $task->log(sprintf(
        __('Fluxo "%s" (ID %d) sofreu reprovação automática global após %d dia(s).', 'escalation'),
        $row['flow_name'],
        $row['id'],
        $row['timeout_days']
      ));

      $volume++;
    }

    $task->addVolume($volume);
    return $volume > 0 ? 1 : 0;
  }

  static function getStatusLabel(int $status): string {
    $labels = [
      self::STATUS_PENDING   => '<span style="color:#d97706">⏳ ' . __('Pendente', 'escalation') . '</span>',
      self::STATUS_APPROVED  => '<span style="color:#16a34a">✅ ' . __('Aprovado', 'escalation') . '</span>',
      self::STATUS_REJECTED  => '<span style="color:#dc2626">❌ ' . __('Reprovado', 'escalation') . '</span>',
      self::STATUS_TIMED_OUT => '<span style="color:#6b7280">⏰ ' . __('Reprovação automática', 'escalation') . '</span>',
    ];
    return $labels[$status] ?? $status;
  }
}
