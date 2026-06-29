<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationFlow extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Fluxo de Aprovação', 'Fluxos de Aprovação', $nb, 'escalation');
  }

  static function getIcon() {
    return 'fas fa-sitemap';
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `name` varchar(255) COLLATE {$default_collation} NOT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT '0',
        `itilcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `timeout_hours` int NOT NULL DEFAULT '0',
        `date_mod` timestamp NULL DEFAULT NULL,
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `is_active` (`is_active`),
        KEY `itilcategories_id` (`itilcategories_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      if (!$DB->fieldExists(self::getTable(), 'itilcategories_id')) {
        $migration->addField(self::getTable(), 'itilcategories_id', "int {$default_key_sign} NOT NULL DEFAULT '0'");
      }
      if (!$DB->fieldExists(self::getTable(), 'timeout_hours')) {
        $migration->addField(self::getTable(), 'timeout_hours', "int NOT NULL DEFAULT '0'");
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
    // Cascata: apagar etapas (que apagam usuários específicos)
    $steps = $DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flows_id' => $this->fields['id']]]);
    foreach ($steps as $step) {
      $obj = new PluginEscalationStep();
      $obj->getFromDB($step['id']);
      $obj->cleanDBonPurge();
      $DB->delete(PluginEscalationStep::getTable(), ['id' => $step['id']]);
    }
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  public function rawSearchOptions() {
    $tab = [];
    $tab[] = ['id' => 'common', 'name' => __('Characteristics')];
    $tab[] = [
      'id' => '1', 'table' => $this->getTable(), 'field' => 'name',
      'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false,
    ];
    $tab[] = [
      'id' => '2', 'table' => $this->getTable(), 'field' => 'is_active',
      'name' => __('Active'), 'datatype' => 'bool', 'massiveaction' => true,
    ];
    $tab[] = [
      'id' => '3', 'table' => 'glpi_itilcategories', 'field' => 'name',
      'name' => __('Categoria ITIL', 'escalation'), 'datatype' => 'dropdown', 'massiveaction' => true,
    ];
    $tab[] = [
      'id' => '4', 'table' => $this->getTable(), 'field' => 'timeout_hours',
      'name' => __('Timeout global (horas)', 'escalation'), 'datatype' => 'number', 'massiveaction' => false,
    ];
    return $tab;
  }

  function showForm($ID, array $options = []) {
    $this->initForm($ID, $options);
    $this->showFormHeader($options);

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Name') . "</td>";
    echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
    echo "<td>" . __('Active') . "</td>";
    echo "<td>"; Dropdown::showYesNo('is_active', $this->fields['is_active']); echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Categoria ITIL', 'escalation') . "</td>";
    echo "<td>";
    ITILCategory::dropdown(['value' => $this->fields['itilcategories_id'], 'name' => 'itilcategories_id']);
    echo "</td>";
    echo "<td>" . __('Timeout global (horas)', 'escalation') . "</td>";
    echo "<td>";
    echo Html::input('timeout_hours', ['value' => $this->fields['timeout_hours'] ?? 0, 'type' => 'number', 'min' => '0', 'style' => 'width:80px']);
    echo " <small>" . __('0 = sem timeout', 'escalation') . "</small>";
    echo "</td>";
    echo "</tr>";

    $this->showFormButtons($options);
    return true;
  }

  function defineTabs($options = []) {
    $ong = [];
    $this->addDefaultFormTab($ong);
    $this->addStandardTab(__CLASS__, $ong, $options);
    return $ong;
  }

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item->getType() === static::class && !$item->isNewItem()) {
      return [1 => __('Etapas', 'escalation')];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === static::class && $tabnum == 1) {
      PluginEscalationStep::showForFlow($item);
    }
    return true;
  }

  // -------------------------------------------------------------------------
  // Hooks
  // -------------------------------------------------------------------------

  static function ticketAdd($ticket) {
    global $DB;

    if (empty($ticket->fields['itilcategories_id'])) return;

    $iterator = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['itilcategories_id' => $ticket->fields['itilcategories_id'], 'is_active' => 1],
    ]);

    foreach ($iterator as $flow_data) {
      PluginEscalationTicketFlow::startFlow($ticket, $flow_data['id']);
    }
  }

  static function preTicketUpdate($ticket) {
    // TODO: bloquear alterações de status enquanto o fluxo estiver pendente (opcional)
    // Exemplo: impedir fechar o chamado enquanto aprovação não foi concluída
  }

  static function ticketUpdate($ticket) {
    // TODO: reagir a mudanças no chamado que possam afetar fluxos ativos
    // Exemplo: recalcular aprovadores se responsável mudou mid-flow
  }
}
