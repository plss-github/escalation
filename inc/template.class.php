<?php
if (!defined('GLPI_ROOT')) { die("Sorry. You can't access this file directly"); }

class PluginEscalationTemplate extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Modelo', 'Modelos', $nb, 'escalation');
  }

  static function getIcon() { return 'ti ti-file-description'; }

  static function installBaseData(Migration $migration, $version) {
    global $DB;
    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `name` varchar(255) COLLATE {$default_collation} NOT NULL DEFAULT '',
        `content` longtext COLLATE {$default_collation},
        `date_mod` timestamp NULL DEFAULT NULL,
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      // Drop legacy single-document column if still present
      if ($DB->fieldExists(self::getTable(), 'documents_id')) {
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` DROP COLUMN `documents_id`");
      }
    }
  }

  static function uninstall() { global $DB; $DB->dropTable(self::getTable()); return true; }

  public function cleanDBonPurge() {
    global $DB;
    $DB->delete('glpi_documents_items', [
      'itemtype' => static::class,
      'items_id' => $this->fields['id'],
    ]);
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  // Returns array of documents_id linked to this template
  static function getDocuments(int $template_id): array {
    global $DB;
    return array_column(
      iterator_to_array($DB->request([
        'SELECT' => ['documents_id'],
        'FROM'   => 'glpi_documents_items',
        'WHERE'  => ['itemtype' => static::class, 'items_id' => $template_id],
      ])),
      'documents_id'
    );
  }

  // Replaces all Document_Item links for a template
  static function saveDocuments(int $template_id, array $doc_ids): void {
    global $DB;
    $DB->delete('glpi_documents_items', ['itemtype' => static::class, 'items_id' => $template_id]);
    foreach (array_unique($doc_ids) as $doc_id) {
      $doc_id = (int) $doc_id;
      if ($doc_id > 0) {
        $DB->insert('glpi_documents_items', [
          'itemtype'     => static::class,
          'items_id'     => $template_id,
          'documents_id' => $doc_id,
          'date_mod'     => $_SESSION['glpi_currenttime'],
        ]);
      }
    }
  }

  public function prepareInputForAdd($input) {
    if (isset($input['documents_ids'])) {
      $this->_documents_ids = (array) $input['documents_ids'];
      unset($input['documents_ids']);
    }
    return $input;
  }

  public function prepareInputForUpdate($input) {
    if (isset($input['documents_ids'])) {
      $this->_documents_ids = (array) $input['documents_ids'];
      unset($input['documents_ids']);
    }
    return $input;
  }

  public function post_addItem() {
    if (isset($this->_documents_ids)) {
      self::saveDocuments($this->getID(), $this->_documents_ids);
      unset($this->_documents_ids);
    }
  }

  public function post_updateItem($history = true) {
    if (isset($this->_documents_ids)) {
      self::saveDocuments($this->getID(), $this->_documents_ids);
      unset($this->_documents_ids);
    }
  }

  public function rawSearchOptions() {
    $tab = [];
    $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
    $tab[] = ['id' => '1', 'table' => $this->getTable(), 'field' => 'name',
              'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false];
    $tab[] = ['id' => '2', 'table' => $this->getTable(), 'field' => 'content',
              'name' => __('Conteúdo', 'escalation'), 'datatype' => 'text', 'massiveaction' => false];
    return $tab;
  }

  function showForm($ID, array $options = []) {
    global $DB;

    $this->initForm($ID, $options);
    if (!empty($_REQUEST['_in_modal'])) {
      $options['target'] = $this->getFormURL() . '?_in_modal=1';
    }
    $this->showFormHeader($options);

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Name') . "</td>";
    echo "<td colspan='3'>" . Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 60]) . "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Conteúdo', 'escalation') . "</td>";
    echo "<td colspan='3'>";
    Html::textarea(['name' => 'content', 'value' => $this->fields['content'] ?? '',
                    'enable_richtext' => true, 'cols' => 100, 'rows' => 10]);
    echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Documentos anexos', 'escalation') . "</td>";
    echo "<td colspan='3'>";

    $current_docs = $this->isNewItem() ? [] : self::getDocuments($this->getID());
    $doc_iter = $DB->request(['FROM' => 'glpi_documents', 'ORDERBY' => ['name ASC']]);
    $doc_options = [];
    foreach ($doc_iter as $doc) {
      $doc_options[$doc['id']] = $doc['name'];
    }

    Dropdown::showFromArray('documents_ids', $doc_options, [
      'values'              => $current_docs,
      'multiple'            => true,
      'display_emptychoice' => false,
      'width'               => '100%',
    ]);

    echo "</td>";
    echo "</tr>";

    $this->showFormButtons($options);
    return true;
  }
}
