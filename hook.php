<?php

require_once __DIR__ . '/inc/contractbudget.class.php';
require_once __DIR__ . '/inc/timeentry.class.php';
require_once __DIR__ . '/inc/alertconfig.class.php';

function plugin_timetracker_install(): bool
{
    global $DB;

    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $migration = new Migration(PLUGIN_TIMETRACKER_VERSION);

    $budget_table = PluginTimetrackerContractBudget::getTable();
    if (!$DB->tableExists($budget_table)) {
        $DB->doQuery(
            "CREATE TABLE `$budget_table` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `contracts_id` int unsigned NOT NULL DEFAULT '0',
               `initial_minutes` int unsigned NOT NULL DEFAULT '0',
               `alert_threshold_minutes` int unsigned NOT NULL DEFAULT '0',
               `is_active` tinyint NOT NULL DEFAULT '1',
               `comment` text,
               `date_mod` timestamp NULL DEFAULT NULL,
               `date_creation` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               UNIQUE KEY `contracts_id` (`contracts_id`),
               KEY `is_active` (`is_active`),
               KEY `date_mod` (`date_mod`),
               KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    } else {
        $migration->addField($budget_table, 'contracts_id', 'integer', ['value' => 0]);
        $migration->addField($budget_table, 'initial_minutes', 'integer', ['value' => 0]);
        $migration->addField($budget_table, 'alert_threshold_minutes', 'integer', ['value' => 0]);
        $migration->addField($budget_table, 'is_active', 'bool', ['value' => 1]);
        $migration->addField($budget_table, 'comment', 'text');
        $migration->addField($budget_table, 'date_mod', 'timestamp', ['null' => true]);
        $migration->addField($budget_table, 'date_creation', 'timestamp', ['null' => true]);
        $migration->addKey($budget_table, 'contracts_id', 'contracts_id', 'UNIQUE');
    }

    $entry_table = PluginTimetrackerTimeEntry::getTable();
    if (!$DB->tableExists($entry_table)) {
        $DB->doQuery(
            "CREATE TABLE `$entry_table` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `tickets_id` int unsigned NOT NULL DEFAULT '0',
               `contracts_id` int unsigned NOT NULL DEFAULT '0',
               `users_id` int unsigned NOT NULL DEFAULT '0',
               `duration_minutes` int unsigned NOT NULL DEFAULT '0',
               `spent_at` date DEFAULT NULL,
               `comment` text,
               `is_deleted` tinyint NOT NULL DEFAULT '0',
               `date_mod` timestamp NULL DEFAULT NULL,
               `date_creation` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `tickets_id` (`tickets_id`),
               KEY `contracts_id` (`contracts_id`),
               KEY `users_id` (`users_id`),
               KEY `spent_at` (`spent_at`),
               KEY `is_deleted` (`is_deleted`),
               KEY `date_mod` (`date_mod`),
               KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    } else {
        $migration->addField($entry_table, 'tickets_id', 'integer', ['value' => 0]);
        $migration->addField($entry_table, 'contracts_id', 'integer', ['value' => 0]);
        $migration->addField($entry_table, 'users_id', 'integer', ['value' => 0]);
        $migration->addField($entry_table, 'duration_minutes', 'integer', ['value' => 0]);
        $migration->addField($entry_table, 'spent_at', 'date', ['null' => true]);
        $migration->addField($entry_table, 'comment', 'text');
        $migration->addField($entry_table, 'is_deleted', 'bool', ['value' => 0]);
        $migration->addField($entry_table, 'date_mod', 'timestamp', ['null' => true]);
        $migration->addField($entry_table, 'date_creation', 'timestamp', ['null' => true]);
        $migration->addKey($entry_table, 'tickets_id');
        $migration->addKey($entry_table, 'contracts_id');
        $migration->addKey($entry_table, 'users_id');
        $migration->addKey($entry_table, 'spent_at');
    }

    $migration->executeMigration();

    $alert_table = PluginTimetrackerAlertConfig::getTable();
    if (!$DB->tableExists($alert_table)) {
        $DB->doQuery(
            "CREATE TABLE `$alert_table` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `contracts_id` int unsigned NOT NULL DEFAULT '0',
               `type` varchar(20) NOT NULL DEFAULT 'deadline',
               `days_before` int unsigned NULL DEFAULT NULL,
               `recipient_email` varchar(255) NOT NULL DEFAULT '',
               `is_active` tinyint NOT NULL DEFAULT '1',
               `last_sent_at` datetime NULL DEFAULT NULL,
               `date_creation` timestamp NULL DEFAULT NULL,
               `date_mod` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `contracts_id` (`contracts_id`),
               KEY `type` (`type`),
               KEY `is_active` (`is_active`),
               KEY `date_mod` (`date_mod`),
               KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    } else {
        $migration->addField($alert_table, 'contracts_id', 'integer', ['value' => 0]);
        $migration->addField($alert_table, 'type', 'string', ['value' => 'deadline']);
        $migration->addField($alert_table, 'days_before', 'integer', ['null' => true]);
        $migration->addField($alert_table, 'recipient_email', 'string', ['value' => '']);
        $migration->addField($alert_table, 'is_active', 'bool', ['value' => 1]);
        $migration->addField($alert_table, 'last_sent_at', 'datetime', ['null' => true]);
        $migration->addField($alert_table, 'date_mod', 'timestamp', ['null' => true]);
        $migration->addField($alert_table, 'date_creation', 'timestamp', ['null' => true]);
        $migration->addKey($alert_table, 'contracts_id');
        $migration->addKey($alert_table, 'type');
        $migration->addKey($alert_table, 'is_active');
    }

    Config::setConfigurationValues('plugin:timetracker', ['version' => PLUGIN_TIMETRACKER_VERSION]);

    CronTask::register(
        'PluginTimetrackerAlertConfig',
        'sendAlerts',
        DAY_TIMESTAMP,
        ['comment' => 'Send timetracker contract alerts', 'state' => CronTask::STATE_WAITING]
    );

    return true;
}

function plugin_timetracker_uninstall(): bool
{
    global $DB;

    $config = new Config();
    $config->deleteConfigurationValues('plugin:timetracker');

    $entry_table = PluginTimetrackerTimeEntry::getTable();
    if ($DB->tableExists($entry_table)) {
        $DB->dropTable($entry_table, true);
    }

    $budget_table = PluginTimetrackerContractBudget::getTable();
    if ($DB->tableExists($budget_table)) {
        $DB->dropTable($budget_table, true);
    }

    $alert_table = PluginTimetrackerAlertConfig::getTable();
    if ($DB->tableExists($alert_table)) {
        $DB->dropTable($alert_table, true);
    }

    CronTask::unregister('PluginTimetrackerAlertConfig');

    return true;
}

