<?php

define('PLUGIN_ESCALATION_VERSION', '1.0.0');

define('PLUGIN_ESCALATION_MIN_GLPI', '10.0.0');
// define('PLUGIN_ESCALATION_MAX_GLPI', '11.0.99');

if (!defined('PLUGINESCALATION_DIR')) {
  define('PLUGINESCALATION_DIR', Plugin::getPhpDir('escalation'));
}

function plugin_init_escalation() {
  global $PLUGIN_HOOKS;

  $PLUGIN_HOOKS['csrf_compliant']['escalation'] = true;

  $PLUGIN_HOOKS['config_page']['escalation'] = 'front/flow.php';
  $PLUGIN_HOOKS['menu_toadd']['escalation'] = ['config' => 'PluginEscalationFlow'];

  $PLUGIN_HOOKS['item_add']['escalation']        = ['Ticket' => ['PluginEscalationFlow', 'ticketAdd']];
  $PLUGIN_HOOKS['pre_item_update']['escalation'] = ['Ticket' => ['PluginEscalationFlow', 'preTicketUpdate']];
  $PLUGIN_HOOKS['item_update']['escalation']     = [
    'Ticket'           => ['PluginEscalationFlow', 'ticketUpdate'],
    'TicketValidation' => ['PluginEscalationTicketFlow', 'validationUpdated'],
  ];

  // Adiciona aba "Aprovação" na tela do chamado
  Plugin::registerClass('PluginEscalationTicketFlow', ['addtabon' => 'Ticket']);

  // Adiciona aba "Escalation" em Configuração > Geral
  Plugin::registerClass('PluginEscalationConfig', ['addtabon' => 'Config']);
}

function plugin_version_escalation() {
  return [
    'name'         => 'Escalation',
    'version'      => PLUGIN_ESCALATION_VERSION,
    'author'       => 'Ampris',
    'homepage'     => 'https://github.com/plss-github/escalation',
    'license'      => 'GPLv2+',
    'requirements' => [
      'glpi' => [
        'min' => PLUGIN_ESCALATION_MIN_GLPI
      ],
    ],
  ];
}

function plugin_escalation_check_prerequisites() {
  return true;
}
