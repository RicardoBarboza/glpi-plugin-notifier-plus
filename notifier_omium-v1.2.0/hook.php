<?php

// Notifier — install / uninstall hooks.

function plugin_notifier_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $migration         = new Migration(PLUGIN_NOTIFIER_VERSION);

    if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
        $query = "CREATE TABLE `glpi_plugin_notifier_notifications` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recipient',
            `actor_users_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Who triggered the event',
            `itemtype`        VARCHAR(100) NOT NULL DEFAULT '',
            `items_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `event`           VARCHAR(50) NOT NULL DEFAULT '',
            `channel`         VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'direct | group',
            `title`           VARCHAR(255) NOT NULL DEFAULT '',
            `message`         TEXT,
            `url`             VARCHAR(500) NOT NULL DEFAULT '',
            `is_read`         TINYINT NOT NULL DEFAULT 0,
            `date_creation`   TIMESTAMP NULL DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `actor_users_id` (`actor_users_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `is_read` (`is_read`),
            KEY `user_unread` (`users_id`, `is_read`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    } elseif (!$DB->fieldExists('glpi_plugin_notifier_notifications', 'channel')) {
        // Upgrade path: pre-channel rows get '' and bypass the read-time
        // filter — that mirrors the old insert-time gating behaviour.
        $DB->doQueryOrDie(
            "ALTER TABLE `glpi_plugin_notifier_notifications`
             ADD COLUMN `channel` VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`",
            $DB->error()
        );
    }

    // Opt-out preferences: missing row = all-on. notify_<type>_<channel>.
    if (!$DB->tableExists('glpi_plugin_notifier_preferences')) {
        $query = "CREATE TABLE `glpi_plugin_notifier_preferences` (
            `users_id`                    INT UNSIGNED NOT NULL,
            `notify_ticket_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_ticket_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_change_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_change_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_problem_direct`       TINYINT NOT NULL DEFAULT 1,
            `notify_problem_group`        TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_direct`   TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_group`    TINYINT NOT NULL DEFAULT 1,
            `sound_enabled`               TINYINT NOT NULL DEFAULT 1,
            `sound_new`                   TINYINT NOT NULL DEFAULT 1,
            `sound_mine`                  TINYINT NOT NULL DEFAULT 1,
            `sound_team`                  TINYINT NOT NULL DEFAULT 1,
            `sound_other`                 TINYINT NOT NULL DEFAULT 1,
            `sound_ended`                 TINYINT NOT NULL DEFAULT 0,
            `notify_others`               TINYINT NOT NULL DEFAULT 0,
            `update_message_style`        VARCHAR(20) NOT NULL DEFAULT 'priority',
            `show_resolved`               TINYINT NOT NULL DEFAULT 1,
            `show_closed`                 TINYINT NOT NULL DEFAULT 1,
            `resolved_closed_style`       VARCHAR(10) NOT NULL DEFAULT 'separate',
            `date_mod`                    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQueryOrDie($query, $DB->error());
    } else {
        // Migration: add columns added after initial release
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_enabled')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `sound_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `notify_projecttask_group`",
                $DB->error()
            );
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_new')) {
            $DB->doQueryOrDie("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_new` TINYINT NOT NULL DEFAULT 1 AFTER `sound_enabled`", $DB->error());
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_mine')) {
            $DB->doQueryOrDie("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_mine` TINYINT NOT NULL DEFAULT 1 AFTER `sound_new`", $DB->error());
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_team')) {
            $DB->doQueryOrDie("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_team` TINYINT NOT NULL DEFAULT 1 AFTER `sound_mine`", $DB->error());
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_other')) {
            $DB->doQueryOrDie("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_other` TINYINT NOT NULL DEFAULT 1 AFTER `sound_team`", $DB->error());
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'sound_ended')) {
            $DB->doQueryOrDie("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_ended` TINYINT NOT NULL DEFAULT 0 AFTER `sound_other`", $DB->error());
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'notify_others')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `notify_others` TINYINT NOT NULL DEFAULT 0 AFTER `sound_ended`",
                $DB->error()
            );
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'show_resolved')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `show_resolved` TINYINT NOT NULL DEFAULT 1 AFTER `update_message_style`",
                $DB->error()
            );
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'show_closed')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `show_closed` TINYINT NOT NULL DEFAULT 1 AFTER `show_resolved`",
                $DB->error()
            );
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'resolved_closed_style')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `resolved_closed_style` VARCHAR(10) NOT NULL DEFAULT 'separate' AFTER `show_closed`",
                $DB->error()
            );
        }
        if (!$DB->fieldExists('glpi_plugin_notifier_preferences', 'update_message_style')) {
            $DB->doQueryOrDie(
                "ALTER TABLE `glpi_plugin_notifier_preferences`
                 ADD COLUMN `update_message_style` VARCHAR(20) NOT NULL DEFAULT 'priority' AFTER `notify_others`",
                $DB->error()
            );
        }
    }

    // Drop legacy RBAC artefacts from earlier versions.
    if ($DB->tableExists('glpi_plugin_notifier_profiles')) {
        $DB->doQueryOrDie("DROP TABLE `glpi_plugin_notifier_profiles`", $DB->error());
    }
    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    $migration->executeMigration();

    return true;
}

function plugin_notifier_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_notifier_notifications',
        'glpi_plugin_notifier_preferences',
        'glpi_plugin_notifier_profiles', // legacy
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`", $DB->error());
        }
    }

    $DB->delete('glpi_profilerights', ['name' => 'plugin_notifier_notification']);

    return true;
}

function plugin_notifier_check_prerequisites(): bool
{
    return true;
}

function plugin_notifier_check_config(bool $verbose = false): bool
{
    return true;
}
