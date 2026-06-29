<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Usuários específicos atribuídos à definição de uma etapa.
 * Resolvidos em PluginEscalationTicketFlow::resolveApprovers() no momento do disparo.
 */
class PluginEscalationStepUser extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Usuário da Etapa', 'Usuários da Etapa', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_steps_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `step_user` (`plugin_escalation_steps_id`, `users_id`),
        KEY `users_id` (`users_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return Session::haveRight(self::$rightname, READ); }
  static function canPurge(): bool  { return Session::haveRight(self::$rightname, UPDATE); }

  static function getUsersForStep(int $step_id): array {
    global $DB;
    return iterator_to_array($DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['plugin_escalation_steps_id' => $step_id],
    ]));
  }

  static function showForStep(PluginEscalationStep $step, int $flow_id): void {
    $step_id = $step->getID();
    $users   = self::getUsersForStep($step_id);

    if (!empty($users)) {
      echo "<table class='tab_cadre_fixe' style='margin-bottom:8px'>";
      foreach ($users as $u) {
        $user = new User();
        $name = $user->getFromDB($u['users_id']) ? $user->getFriendlyName() : '#' . $u['users_id'];
        echo "<tr class='tab_bg_1'><td>" . htmlspecialchars($name) . "</td><td>";
        echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<input type='hidden' name='id' value='" . $u['id'] . "'>";
        echo "<input type='hidden' name='plugin_escalation_steps_id' value='$step_id'>";
        echo "<input type='hidden' name='plugin_escalation_flows_id' value='$flow_id'>";
        echo "<button name='purge' type='submit' class='vsubmit btn-sm' style='background:#ef4444;border-color:#ef4444;padding:2px 6px'>";
        echo "<i class='fas fa-times'></i></button>";
        Html::closeForm();
        echo "</td></tr>";
      }
      echo "</table>";
    }

    echo "<form style='display:inline-flex;gap:8px;align-items:center' action='" . static::getFormURL() . "' method='post'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "<input type='hidden' name='plugin_escalation_steps_id' value='$step_id'>";
    echo "<input type='hidden' name='plugin_escalation_flows_id' value='$flow_id'>";
    User::dropdown(['name' => 'users_id', 'right' => 'all']);
    echo "<button name='add' type='submit' class='vsubmit' style='display:inline-flex;gap:6px'>";
    echo "<i class='fas fa-plus'></i> " . __('Adicionar usuário', 'escalation') . "</button>";
    Html::closeForm();
  }
}
