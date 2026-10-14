<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationConfig extends CommonGLPI {

  static function getTypeName($nb = 0) {
    return __('Escalation', 'escalation');
  }

  static function getIcon() {
    return 'ti ti-sitemap';
  }

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item instanceof Config) {
      return CommonGLPI::createTabEntry(self::getTypeName(1), 0, null, self::getIcon());
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item instanceof Config) {
      self::showForm();
    }
    return true;
  }

  // -------------------------------------------------------------------------
  // Leitura / escrita de configuração
  // -------------------------------------------------------------------------

  static function getDefaults(): array {
    return [
      'hierarchy_type' => 'Group', // 'Group' ou 'UserCategory'
    ];
  }

  /** @var array|null Cache por requisição (a hierarquia consulta a config a cada nível percorrido). */
  private static ?array $config_cache = null;

  static function getConfig(): array {
    if (self::$config_cache === null) {
      self::$config_cache = Config::getConfigurationValues('plugin_escalation', array_keys(self::getDefaults()));
    }
    return self::$config_cache;
  }

  /**
   * Caminho web do plugin. No GLPI 11 os recursos de plugins são servidos em
   * /plugins/<key> e Plugin::getWebDir() está deprecated; no GLPI 10 ele ainda
   * é necessário (o plugin pode estar em /marketplace).
   *
   * @param bool $full true para incluir o root_doc
   */
  static function getWebPath(bool $full = true): string {
    global $CFG_GLPI;
    if (version_compare(GLPI_VERSION, '11', '<')) {
      return (string) Plugin::getWebDir('escalation', $full);
    }
    return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/escalation';
  }

  static function setDefaults(): void {
    $existing = Config::getConfigurationValues('plugin_escalation', array_keys(self::getDefaults()));
    $toSet    = [];
    foreach (self::getDefaults() as $key => $default) {
      if (!array_key_exists($key, $existing) || $existing[$key] === '') {
        $toSet[$key] = $default;
      }
    }
    if (!empty($toSet)) {
      Config::setConfigurationValues('plugin_escalation', $toSet);
    }
  }

  static function removeConfig(): void {
    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin_escalation']);
  }

  // -------------------------------------------------------------------------
  // Formulário
  // -------------------------------------------------------------------------

  static function showForm(): void {
    $config       = self::getConfig();
    $hier_type    = $config['hierarchy_type'] ?? 'Group';
    $form_url     = PluginEscalationConfig::getWebPath() . '/front/config.form.php';
    $levels       = PluginEscalationHierarchyLevel::getAllLevels();

    // ------------------------------------------------------------------
    // Seção 1 — Configurações gerais (tipo de hierarquia)
    // ------------------------------------------------------------------
    echo "<form action='$form_url' method='post'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('_action', ['value' => 'save_config']);

    echo "<table class='tab_cadre_fixe'>";
    echo "<tr class='tab_bg_1'>";
    echo "<th colspan='4'>" . __('Hierarquia de Aprovação', 'escalation') . "</th>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Tipo de hierarquia', 'escalation') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('hierarchy_type', [
      'Group'        => __('Grupo', 'escalation'),
      'UserCategory' => __('Categoria de usuário', 'escalation'),
    ], ['value' => $hier_type]);
    echo "</td>";
    echo "<td colspan='2'><span class='text-muted'>" .
      __('Define se os níveis hierárquicos são identificados pelo grupo ou pela categoria do usuário.', 'escalation') .
      "</span></td>";
    echo "</tr>";

    echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
    echo "<button type='submit' name='update' class='btn btn-primary' title='" . _sx('button', 'Save') . "'>";
    echo "<i class='ti ti-device-floppy'></i><span class='d-none d-xxl-block'>" . _sx('button', 'Save') . "</span></button>";
    echo "</td></tr>";
    echo "</table>";
    Html::closeForm();

    // ------------------------------------------------------------------
    // Seção 2 — Mapeamento de níveis (formulários separados por ação)
    // ------------------------------------------------------------------
    echo "<hr class='my-4'>";
    echo "<table class='tab_cadre_fixe'>";
    echo "<tr class='tab_bg_1'>";
    echo "<th>" . __('Nível', 'escalation') . "</th>";
    echo "<th>" . ($hier_type === 'UserCategory'
        ? __('Categoria de usuário', 'escalation')
        : __('Grupo', 'escalation')) . "</th>";
    echo "<th style='width:60px'></th>";
    echo "</tr>";

    if (empty($levels)) {
      echo "<tr class='tab_bg_1'><td colspan='3' class='center'>";
      echo "<em>" . __('Nenhum nível configurado.', 'escalation') . "</em>";
      echo "</td></tr>";
    } else {
      foreach ($levels as $l) {
        echo "<tr class='tab_bg_1'>";
        echo "<td><b>" . sprintf(__('Nível %d', 'escalation'), $l['level']) . "</b></td>";
        echo "<td>" . htmlspecialchars($l['item_name']) . "</td>";
        echo "<td>";
        echo "<form style='display:inline' action='$form_url' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('_action',  ['value' => 'delete_level']);
        echo Html::hidden('level_id', ['value' => $l['id']]);
        echo "<button type='submit' class='vsubmit btn-sm' style='color:#ffffff;background:#ef4444;border-color:#ef4444;padding:2px 8px' "
          . "onclick=\"return confirm('" . __('Remover este nível?', 'escalation') . "')\">"
          . "<i class='ti ti-trash'></i></button>";
        Html::closeForm();
        echo "</td></tr>";
      }
    }

    echo "</table>";

    // Formulário de adicionar nível (fora da tabela — form dentro de tr/td é HTML inválido)
    echo "<form action='$form_url' method='post'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('_action',    ['value' => 'add_level']);
    echo Html::hidden('new_itemtype', ['value' => $hier_type]);

    echo "<table class='tab_cadre_fixe'><tr class='tab_bg_2'>";
    echo "<td style='width:120px'>" . __('Nível', 'escalation') . "</td>";
    echo "<td style='width:120px'>";
    echo Html::input('new_level', [
      'value' => count($levels) + 1,
      'type'  => 'number',
      'min'   => '1',
      'style' => 'width:70px',
    ]);
    echo "</td>";
    echo "<td>" . ($hier_type === 'UserCategory'
        ? __('Categoria de usuário', 'escalation')
        : __('Grupo', 'escalation')) . "</td>";
    echo "<td>";
    Dropdown::show($hier_type, ['name' => 'new_items_id', 'value' => 0]);
    echo "</td>";
    echo "<td>";
    echo "<button type='submit' class='btn btn-primary'>"
      . "<i class='ti ti-plus'></i><span class='d-none d-xxl-block'>" . _sx('button', 'Add') . "</span></button>";
    echo "</td>";
    echo "</tr></table>";
    Html::closeForm();
  }
}
