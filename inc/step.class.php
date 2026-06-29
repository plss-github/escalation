<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationStep extends CommonDBTM {
  static $rightname = 'config';

  // Tipos de etapa
  const TYPE_PEOPLE    = 'people';
  const TYPE_HIERARCHY = 'hierarchy';

  // Tipos de quórum (apenas para TYPE_PEOPLE)
  const QUORUM_MIN1     = 'min1';
  const QUORUM_MAJORITY = 'majority';
  const QUORUM_ALL      = 'all';

  static function getTypeName($nb = 0) {
    return _n('Etapa', 'Etapas', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flows_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `name` varchar(255) COLLATE {$default_collation} NOT NULL,
        `type` varchar(32) COLLATE {$default_collation} NOT NULL DEFAULT '" . self::TYPE_PEOPLE . "',
        `step_order` int NOT NULL DEFAULT '0',
        `timeout_days` int NOT NULL DEFAULT '0',
        `quorum_type` varchar(32) COLLATE {$default_collation} NOT NULL DEFAULT '" . self::QUORUM_MIN1 . "',
        `hierarchy_level` int NOT NULL DEFAULT '0',
        `include_requesters` tinyint(1) NOT NULL DEFAULT '0',
        `include_assigned` tinyint(1) NOT NULL DEFAULT '0',
        `include_observers` tinyint(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `plugin_escalation_flows_id` (`plugin_escalation_flows_id`),
        KEY `step_order` (`step_order`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      if ($DB->fieldExists(self::getTable(), 'timeout_hours') && !$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` CHANGE `timeout_hours` `timeout_days` int NOT NULL DEFAULT '0'");
      } elseif (!$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $migration->addField(self::getTable(), 'timeout_days', "int NOT NULL DEFAULT '0'");
      }
      if (!$DB->fieldExists(self::getTable(), 'hierarchy_level')) {
        $migration->addField(self::getTable(), 'hierarchy_level', "int NOT NULL DEFAULT '0'");
      }
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  public function cleanDBonPurge() {
    global $DB;
    $DB->delete(PluginEscalationStepUser::getTable(), ['plugin_escalation_steps_id' => $this->fields['id']]);
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  static function getTypeOptions(): array {
    return [
      self::TYPE_PEOPLE    => __('Pessoas', 'escalation'),
      self::TYPE_HIERARCHY => __('Hierarquia', 'escalation'),
    ];
  }

  static function getQuorumOptions(): array {
    return [
      self::QUORUM_MIN1     => __('Mínimo 1', 'escalation'),
      self::QUORUM_MAJORITY => __('Maioria', 'escalation'),
      self::QUORUM_ALL      => __('Todos', 'escalation'),
    ];
  }

  static function getTypeName_($type): string {
    return self::getTypeOptions()[$type] ?? $type;
  }

  static function getQuorumName($quorum): string {
    return self::getQuorumOptions()[$quorum] ?? $quorum;
  }

  // -------------------------------------------------------------------------
  // Builder UI (exibida na aba "Etapas" do fluxo)
  // -------------------------------------------------------------------------

  static function showForFlow(PluginEscalationFlow $flow) {
    global $DB;

    $flow_id = $flow->getID();
    if (!$flow->can($flow_id, READ)) return false;
    $canedit = $flow->can($flow_id, UPDATE);

    // Formulário para adicionar etapa
    if ($canedit) {
      echo "<form action='" . static::getFormURL() . "' method='post'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_1'><th colspan='9'>" . __('Adicionar Etapa', 'escalation') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";

      echo "<td>" . __('Nome', 'escalation') . "</td>";
      echo "<td>" . Html::input('name', ['style' => 'width:160px']) . "</td>";

      echo "<td>" . __('Tipo', 'escalation') . "</td>";
      echo "<td>";
      $type_options = self::getTypeOptions();
      Dropdown::showFromArray('type', $type_options, [
        'value'     => self::TYPE_PEOPLE,
        'on_change' => "escalationToggleInlineFields(this.value)",
      ]);
      echo "</td>";

      echo "<td>" . __('Reprovação automática (dias)', 'escalation') . "</td>";
      echo "<td>";
      echo Html::input('timeout_days', ['value' => '0', 'type' => 'number', 'min' => '0', 'style' => 'width:60px']);
      echo "</td>";

      // Células de hierarquia: começam ocultas (tipo padrão é Pessoas)
      echo "<td id='inline_hier_label' style='display:none'>" . __('Nível necessário', 'escalation') . "</td>";
      echo "<td id='inline_hier_field' style='display:none'>";
      echo Html::input('hierarchy_level', ['value' => '1', 'type' => 'number', 'min' => '1', 'style' => 'width:60px']);
      echo "</td>";

      // Botão sempre visível
      echo "<td>";
      echo "<button type='submit' name='add' class='btn btn-primary btn-sm'>";
      echo "<i class='fas fa-plus'></i>&nbsp;" . _sx('button', 'Add') . "</button>";
      echo "</td>";
      echo "</tr></table>";

      echo "<script>
        function escalationToggleInlineFields(type) {
          var show = (type === '" . self::TYPE_HIERARCHY . "');
          ['inline_hier_label','inline_hier_field'].forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.style.display = show ? '' : 'none';
          });
        }
      </script>";
      Html::closeForm();
    }

    // Lista de etapas
    $steps = $DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_escalation_flows_id' => $flow_id],
      'ORDERBY' => ['step_order ASC'],
    ]);

    $steps_array = iterator_to_array($steps);
    if (empty($steps_array)) {
      echo "<p class='center'>" . __('Nenhuma etapa configurada.', 'escalation') . "</p>";
      return true;
    }

    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>#</th>";
    echo "<th>" . __('Nome', 'escalation') . "</th>";
    echo "<th>" . __('Tipo', 'escalation') . "</th>";
    echo "<th>" . __('Quórum', 'escalation') . "</th>";
    echo "<th>" . __('Reprovação automática', 'escalation') . "</th>";
    echo "<th>" . __('Nível hierárquico', 'escalation') . "</th>";
    echo "<th>" . __('Fontes', 'escalation') . "</th>";
    if ($canedit) echo "<th>" . __('Ações', 'escalation') . "</th>";
    echo "</tr>";

    $total = count($steps_array);
    foreach ($steps_array as $i => $step) {
      $sources = self::buildSourcesLabel($step);
      echo "<tr class='tab_bg_" . (($i % 2) + 1) . "'>";
      echo "<td>" . ($i + 1) . "</td>";
      echo "<td><a href='" . static::getFormURL() . "?id=" . $step['id'] . "'>" . htmlspecialchars($step['name']) . "</a></td>";
      echo "<td>" . self::getTypeName_($step['type']) . "</td>";
      echo "<td>" . ($step['type'] === self::TYPE_PEOPLE ? self::getQuorumName($step['quorum_type']) : '—') . "</td>";
      echo "<td>" . ($step['timeout_days'] > 0 ? $step['timeout_days'] . 'd' : '—') . "</td>";
      echo "<td>" . ($step['type'] === self::TYPE_HIERARCHY && $step['hierarchy_level'] > 0
        ? sprintf(__('Nível %d', 'escalation'), $step['hierarchy_level'])
        : '—') . "</td>";
      echo "<td>" . htmlspecialchars($sources) . "</td>";

      if ($canedit) {
        echo "<td class='nowrap'>";

        // Mover para cima
        if ($i > 0) {
          echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $step['id']]);
          echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
          echo "<button name='move_up' type='submit' class='btn btn-sm btn-icon btn-ghost-secondary' title='" . __('Mover para cima', 'escalation') . "'><i class='fas fa-arrow-up'></i></button>";
          Html::closeForm();
        }

        // Mover para baixo
        if ($i < $total - 1) {
          echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $step['id']]);
          echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
          echo "<button name='move_down' type='submit' class='btn btn-sm btn-icon btn-ghost-secondary' title='" . __('Mover para baixo', 'escalation') . "'><i class='fas fa-arrow-down'></i></button>";
          Html::closeForm();
        }

        echo " <a href='" . static::getFormURL() . "?id=" . $step['id'] . "' class='btn btn-sm btn-icon btn-ghost-info' title='" . __('Editar', 'escalation') . "'><i class='fas fa-pencil-alt'></i></a>";

        echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => $step['id']]);
        echo "<button name='purge' type='submit' class='btn btn-sm btn-icon btn-ghost-danger' title='" . __('Excluir', 'escalation') . "' onclick=\"return confirm('" . __('Excluir esta etapa?', 'escalation') . "')\">";
        echo "<i class='fas fa-trash-alt'></i></button>";
        Html::closeForm();

        echo "</td>";
      }
      echo "</tr>";
    }
    echo "</table></div>";
    return true;
  }

  function showForm($ID, array $options = []) {
    $step = new self();
    if ($ID > 0) {
      $step->getFromDB($ID);
    } else {
      $step->getEmpty();
    }
    $flow_id     = $step->fields['plugin_escalation_flows_id'] ?? ($_GET['plugin_escalation_flows_id'] ?? 0);
    $current_type = $step->fields['type'] ?? self::TYPE_PEOPLE;
    $is_hier      = ($current_type === self::TYPE_HIERARCHY);

    $step->initForm($ID, $options);
    $step->showFormHeader($options);

    echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);

    // Linha 1: Nome + Tipo
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Nome', 'escalation') . "</td>";
    echo "<td>" . Html::input('name', ['value' => $step->fields['name'] ?? '']) . "</td>";
    echo "<td>" . __('Tipo', 'escalation') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('type', self::getTypeOptions(), [
      'value'     => $current_type,
      'on_change' => "escalationToggleStepFields(this.value)",
    ]);
    echo "</td>";
    echo "</tr>";

    // Linha 2: Reprovação automática
    echo "<tr class='tab_bg_2'>";
    echo "<td>" . __('Reprovação automática (dias)', 'escalation') . "</td>";
    echo "<td>";
    echo Html::input('timeout_days', [
      'value' => (int)($step->fields['timeout_days'] ?? 0),
      'type'  => 'number',
      'min'   => '0',
      'style' => 'width:80px',
    ]);
    echo "&nbsp;<span class='text-muted small'>" . __('0 = desativado', 'escalation') . "</span>";
    echo "</td>";
    echo "<td colspan='2'></td>";
    echo "</tr>";

    // Linha 3: Nível hierárquico (visível apenas para Hierarquia)
    echo "<tr class='tab_bg_1' id='escalation_hier_row'" . ($is_hier ? '' : " style='display:none'") . ">";
    echo "<td>" . __('Nível necessário', 'escalation') . "</td>";
    echo "<td>";
    echo Html::input('hierarchy_level', [
      'value' => (int)($step->fields['hierarchy_level'] ?? 1),
      'type'  => 'number',
      'min'   => '1',
      'style' => 'width:80px',
    ]);
    echo "&nbsp;<span class='text-muted small'>" . __('1 = nível mais alto', 'escalation') . "</span>";
    echo "</td>";
    echo "<td colspan='2'>";
    echo "<span class='text-muted'>" . __('O sistema percorre a cadeia de supervisores até encontrar alguém com nível ≤ ao necessário.', 'escalation') . "</span>";
    echo "</td>";
    echo "</tr>";

    // Linha 4: Quórum + Fontes (visível apenas para Pessoas)
    echo "<tr class='tab_bg_2' id='escalation_people_row'" . ($is_hier ? " style='display:none'" : '') . ">";
    echo "<td>" . __('Quórum', 'escalation') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('quorum_type', self::getQuorumOptions(), [
      'value' => $step->fields['quorum_type'] ?? self::QUORUM_MIN1,
    ]);
    echo "</td>";
    echo "<td>" . __('Fontes de aprovadores', 'escalation') . "</td>";
    echo "<td>";
    echo "<label class='me-3'><input type='checkbox' name='include_requesters' value='1'" . (!empty($step->fields['include_requesters']) ? ' checked' : '') . ">&nbsp;" . __('Solicitantes', 'escalation') . "</label>";
    echo "<label class='me-3'><input type='checkbox' name='include_assigned'   value='1'" . (!empty($step->fields['include_assigned'])   ? ' checked' : '') . ">&nbsp;" . __('Responsáveis', 'escalation') . "</label>";
    echo "<label><input type='checkbox' name='include_observers'               value='1'" . (!empty($step->fields['include_observers'])  ? ' checked' : '') . ">&nbsp;" . __('Observadores', 'escalation') . "</label>";
    echo "</td>";
    echo "</tr>";

    echo "<script>
      function escalationToggleStepFields(type) {
        var isHier = (type === '" . self::TYPE_HIERARCHY . "');
        var hierRow    = document.getElementById('escalation_hier_row');
        var peopleRow  = document.getElementById('escalation_people_row');
        var usersBlock = document.getElementById('escalation_users_section');
        if (hierRow)    hierRow.style.display    = isHier ? '' : 'none';
        if (peopleRow)  peopleRow.style.display  = isHier ? 'none' : '';
        if (usersBlock) usersBlock.style.display = isHier ? 'none' : '';
      }
    </script>";

    $step->showFormButtons($options);

    // Seção de usuários — fora do <form> principal para evitar forms aninhados
    if ($ID > 0) {
      $users_style = $is_hier ? "style='display:none'" : '';
      echo "<div id='escalation_users_section' class='spaced' $users_style>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='headerRow'><th colspan='2'>" . __('Usuários específicos', 'escalation') . "</th></tr>";
      echo "<tr class='tab_bg_1'><td colspan='2'>";
      PluginEscalationStepUser::showForStep($step, $flow_id);
      echo "</td></tr>";
      echo "</table>";
      echo "</div>";
    }

    return true;
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  static function buildSourcesLabel(array $step): string {
    $parts = [];
    if ($step['include_requesters']) $parts[] = __('Solicitantes', 'escalation');
    if ($step['include_assigned'])   $parts[] = __('Responsáveis', 'escalation');
    if ($step['include_observers'])  $parts[] = __('Observadores', 'escalation');

    $users = PluginEscalationStepUser::getUsersForStep($step['id']);
    foreach ($users as $u) {
      $user = new User();
      if ($user->getFromDB($u['users_id'])) {
        $parts[] = $user->getFriendlyName();
      }
    }
    return empty($parts) ? '—' : implode(', ', $parts);
  }

  static function getNextOrder(int $flow_id): int {
    global $DB;
    $row = $DB->request([
      'SELECT' => ['MAX' => 'step_order AS max_order'],
      'FROM'   => self::getTable(),
      'WHERE'  => ['plugin_escalation_flows_id' => $flow_id],
    ])->current();
    return ($row['max_order'] ?? 0) + 1;
  }

  static function swapOrder(int $id_a, int $id_b): void {
    global $DB;
    $a = (new self());
    $a->getFromDB($id_a);
    $b = (new self());
    $b->getFromDB($id_b);
    $DB->update(self::getTable(), ['step_order' => $b->fields['step_order']], ['id' => $id_a]);
    $DB->update(self::getTable(), ['step_order' => $a->fields['step_order']], ['id' => $id_b]);
  }

  static function getAdjacentStep(int $id, string $direction): ?array {
    global $DB;
    $current = (new self());
    $current->getFromDB($id);
    $flow_id = $current->fields['plugin_escalation_flows_id'];
    $order   = $current->fields['step_order'];

    if ($direction === 'up') {
      $row = $DB->request([
        'FROM'    => self::getTable(),
        'WHERE'   => ['plugin_escalation_flows_id' => $flow_id, ['step_order' => ['<', $order]]],
        'ORDERBY' => ['step_order DESC'],
        'LIMIT'   => 1,
      ])->current();
    } else {
      $row = $DB->request([
        'FROM'    => self::getTable(),
        'WHERE'   => ['plugin_escalation_flows_id' => $flow_id, ['step_order' => ['>', $order]]],
        'ORDERBY' => ['step_order ASC'],
        'LIMIT'   => 1,
      ])->current();
    }
    return $row ?: null;
  }
}
