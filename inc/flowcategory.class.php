<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationFlowCategory extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Categoria do Fluxo', 'Categorias do Fluxo', $nb, 'escalation');
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
        `itilcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `flow_category` (`plugin_escalation_flows_id`, `itilcategories_id`),
        KEY `itilcategories_id` (`itilcategories_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  // Migra dados existentes da coluna itilcategories_id da tabela de fluxos
  // para a tabela de junção. Chamado após todas as tabelas serem criadas.
  static function migrateFromFlows(): void {
    global $DB;

    $flows_table = PluginEscalationFlow::getTable();
    if (!$DB->fieldExists($flows_table, 'itilcategories_id')) return;

    $existing = $DB->request([
      'FROM'   => $flows_table,
      'WHERE'  => ['itilcategories_id' => ['>', 0]],
    ]);

    foreach ($existing as $flow) {
      $already = countElementsInTable(self::getTable(), [
        'plugin_escalation_flows_id' => $flow['id'],
        'itilcategories_id'          => $flow['itilcategories_id'],
      ]);
      if (!$already) {
        $DB->insert(self::getTable(), [
          'plugin_escalation_flows_id' => $flow['id'],
          'itilcategories_id'          => $flow['itilcategories_id'],
        ]);
      }
    }

    $DB->doQuery("ALTER TABLE `$flows_table` DROP COLUMN `itilcategories_id`");
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function getForFlow(int $flow_id): array {
    global $DB;
    return array_column(
      iterator_to_array($DB->request([
        'FROM'  => self::getTable(),
        'WHERE' => ['plugin_escalation_flows_id' => $flow_id],
      ])),
      'itilcategories_id'
    );
  }

  static function saveForFlow(int $flow_id, array $category_ids): void {
    global $DB;

    $DB->delete(self::getTable(), ['plugin_escalation_flows_id' => $flow_id]);

    foreach (array_unique($category_ids) as $cat_id) {
      $cat_id = (int)$cat_id;
      if ($cat_id > 0) {
        $DB->insert(self::getTable(), [
          'plugin_escalation_flows_id' => $flow_id,
          'itilcategories_id'          => $cat_id,
        ]);
      }
    }
  }
}