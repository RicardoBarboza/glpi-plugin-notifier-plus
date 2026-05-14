<?php

/**
 * -------------------------------------------------------------------------
 * Notifier+ - Plugin de notificações em tempo real para o GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Notifier+.
 *
 * Notifier+ is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Notifier+ is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Notifier+. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2024-2026 DVBNL
 * @copyright Copyright (C) 2026 Ricardo Barboza - Omium Tecnologias & Negócios
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/dvbnl/glpi-plugin-notifier (original)
 * -------------------------------------------------------------------------
 *
 * Fork: Notifier+ by Omium Tecnologias & Negócios
 * Based on glpi-plugin-notifier by DVBNL and fork by dinoue
 *
 * Improvements over original:
 * - Real-time notifications for unassigned tickets (including via email)
 * - Categorized queue tabs: Todos, Novos, Meus, Equipe, Demais
 * - Per-tab unread badge counters
 * - Sound notification with toggle preference
 * - Ticket status display in notification items
 * - Auto mark-as-read when opening ticket directly from GLPI
 * - Full Brazilian Portuguese translation
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_NOTIFIER_VERSION', '1.2.0');
define('PLUGIN_NOTIFIER_MIN_GLPI', '10.0.0');
define('PLUGIN_NOTIFIER_MAX_GLPI', '11.99.99');

if (!function_exists('htmlescape')) {
    function htmlescape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function plugin_version_notifier(): array
{
    return [
        'name'           => 'Notifier+ - Plugin de notificacoes em tempo real para o GLPI com suporte a chamados por e-mail, categorizacao por fila e som',
        'version'        => PLUGIN_NOTIFIER_VERSION,
        'author'         => 'DVBNL, dinoue, Ricardo Barboza (Omium Tecnologias & Negocios)',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/RicardoBarboza/glpi-plugin-notifier-plus',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_NOTIFIER_MIN_GLPI,
                'max' => PLUGIN_NOTIFIER_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

function plugin_init_notifier(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['notifier'] = true;

    if (is_dir(__DIR__ . '/public/')) {
        $PLUGIN_HOOKS['add_css']['notifier'] = 'public/notifier.css';
        $PLUGIN_HOOKS['add_javascript']['notifier'] = 'public/notifier.js';
    } else {
        $PLUGIN_HOOKS['add_css']['notifier'] = 'css/notifier.css';
        $PLUGIN_HOOKS['add_javascript']['notifier'] = 'js/notifier.js';
    }

    if (
        isset($_SESSION['glpiactiveprofile']['interface'])
        && $_SESSION['glpiactiveprofile']['interface'] === 'helpdesk'
    ) {
        unset($PLUGIN_HOOKS['add_css']['notifier']);
        unset($PLUGIN_HOOKS['add_javascript']['notifier']);
    }

    Plugin::registerClass(
        'GlpiPlugin\\Notifier\\Notification',
        ['addtabon' => []]
    );

    $watched_types = [
        'Ticket',
        'Change',
        'Problem',
        'ProjectTask',
        'ITILFollowup',
        'TicketTask',
        'ChangeTask',
        'ProblemTask',
        'ITILSolution',
        'Ticket_User',
        'Change_User',
        'Problem_User',
        'Group_Ticket',
        'Change_Group',
        'Group_Problem',
        'ProjectTaskTeam',
    ];

    $dispatch = ['GlpiPlugin\\Notifier\\Notification', 'handleItemEvent'];

    foreach ($watched_types as $type) {
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['notifier'][$type]    = $dispatch;
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['notifier'][$type] = $dispatch;
    }

    $cleanup = ['GlpiPlugin\\Notifier\\Notification', 'cleanForItem'];
    foreach ($watched_types as $type) {
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['notifier'][$type] = $cleanup;
    }
}
