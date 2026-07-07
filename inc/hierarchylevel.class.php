<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Mapeia grupos ou categorias de usuário a níveis hierárquicos.
 * Nível 1 = mais alto (ex.: diretoria). Nível N = mais baixo.
 */
class PluginEscalationHierarchyLevel extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Nível de Hierarquia', 'Níveis de Hierarquia', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `level` int NOT NULL DEFAULT '1',
        `items_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `itemtype` varchar(50) COLLATE {$default_collation} NOT NULL DEFAULT 'Group',
        PRIMARY KEY (`id`),
        UNIQUE KEY `level_item` (`level`, `items_id`, `itemtype`),
        KEY `level` (`level`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  // -------------------------------------------------------------------------
  // Helpers de leitura
  // -------------------------------------------------------------------------

  /**
   * Retorna o nível hierárquico de um grupo ou categoria.
   * Retorna null se não mapeado.
   */
  static function getLevelForItem(int $items_id, string $itemtype): ?int {
    global $DB;
    $row = $DB->request([
      'SELECT' => ['level'],
      'FROM'   => self::getTable(),
      'WHERE'  => ['items_id' => $items_id, 'itemtype' => $itemtype],
      'LIMIT'  => 1,
    ])->current();
    return $row ? (int) $row['level'] : null;
  }

  /**
   * Retorna o menor nível (mais alto na hierarquia) dos grupos de um usuário.
   * Usado quando hierarchy_type = 'Group'.
   */
  static function getUserLevelByGroup(int $user_id): ?int {
    global $DB;
    $rows = $DB->request([
      'SELECT' => ['hl.level'],
      'FROM'   => 'glpi_groups_users AS gu',
      'INNER JOIN' => [
        self::getTable() . ' AS hl' => [
          'ON' => ['gu' => 'groups_id', 'hl' => 'items_id'],
        ],
      ],
      'WHERE'  => ['gu.users_id' => $user_id, 'hl.itemtype' => 'Group'],
      'ORDERBY' => ['hl.level ASC'],
      'LIMIT'  => 1,
    ])->current();
    return $rows ? (int) $rows['level'] : null;
  }

  /**
   * Retorna o nível hierárquico pela categoria do usuário.
   * Usado quando hierarchy_type = 'UserCategory'.
   */
  static function getUserLevelByCategory(int $user_id): ?int {
    $user = new User();
    if (!$user->getFromDB($user_id)) return null;
    $cat_id = (int) ($user->fields['usercategories_id'] ?? 0);
    if ($cat_id <= 0) return null;
    return self::getLevelForItem($cat_id, 'UserCategory');
  }

  /**
   * Retorna o nível do usuário de acordo com o tipo configurado globalmente.
   */
  static function getUserLevel(int $user_id): ?int {
    $config = PluginEscalationConfig::getConfig();
    $type   = $config['hierarchy_type'] ?? 'Group';
    return $type === 'UserCategory'
      ? self::getUserLevelByCategory($user_id)
      : self::getUserLevelByGroup($user_id);
  }

  /**
   * Retorna todos os níveis cadastrados em ordem crescente.
   * @return array  [ ['id'=>, 'level'=>, 'items_id'=>, 'itemtype'=>, 'item_name'=>], ... ]
   */
  static function getAllLevels(): array {
    global $DB;
    $rows = $DB->request([
      'FROM'    => self::getTable(),
      'ORDERBY' => ['level ASC', 'id ASC'],
    ]);
    $result = [];
    foreach ($rows as $row) {
      $row['item_name'] = self::getItemName((int) $row['items_id'], $row['itemtype']);
      $result[] = $row;
    }
    return $result;
  }

  static function getItemName(int $id, string $itemtype): string {
    if ($id <= 0) return '—';
    $obj = new $itemtype();
    return $obj->getFromDB($id) ? $obj->getFriendlyName() : '#' . $id;
  }

  /**
   * Retorna array de níveis únicos definidos [ 1 => 'Nível 1', 2 => 'Nível 2', ... ]
   */
  static function getLevelOptions(): array {
    global $DB;
    $rows = $DB->request([
      'SELECT DISTINCT' => ['level'],
      'FROM'            => self::getTable(),
      'ORDERBY'         => ['level ASC'],
    ]);
    $opts = [];
    foreach ($rows as $row) {
      $opts[(int) $row['level']] = sprintf(__('Nível %d', 'escalation'), $row['level']);
    }
    return $opts;
  }
}
