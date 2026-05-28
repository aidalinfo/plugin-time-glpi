<?php

define('PLUGIN_TIMETRACKER_VERSION', '0.2.0');
define('PLUGIN_TIMETRACKER_MIN_GLPI', '11.0.0');
define('PLUGIN_TIMETRACKER_MAX_GLPI', '11.1.0');

$folder = basename(__DIR__);
if ($folder !== 'timetracker' && class_exists('Session')) {
    Session::addMessageAfterRedirect(
        sprintf(__tt('Please rename the plugin folder "%s" to "timetracker".'), $folder),
        false,
        WARNING
    );
}

function plugin_init_timetracker(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(PluginTimetrackerContractBudget::class, [
        'addtabon' => ['Contract'],
    ]);

    Plugin::registerClass(PluginTimetrackerTimeEntry::class, [
        'addtabon' => ['Ticket'],
    ]);

    Plugin::registerClass(PluginTimetrackerTravelEntry::class, [
        'addtabon' => ['Ticket'],
    ]);

    Plugin::registerClass(PluginTimetrackerDashboard::class);

    Plugin::registerClass(PluginTimetrackerAlertConfig::class, [
        'addtabon' => ['Contract'],
    ]);

    Plugin::registerClass(PluginTimetrackerMonthlyReport::class);

    Plugin::registerClass(PluginTimetrackerUserRate::class, [
        'addtabon' => ['User'],
    ]);

    $PLUGIN_HOOKS['csrf_compliant']['timetracker'] = true;
    $PLUGIN_HOOKS['config_page']['timetracker'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['timetracker'] = [
        'tools' => PluginTimetrackerDashboard::class,
    ];
}

function plugin_version_timetracker(): array
{
    return [
        'name'         => __tt('Contract time tracking'),
        'version'      => PLUGIN_TIMETRACKER_VERSION,
        'author'       => 'Aidalinfo',
        'license'      => 'MIT',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TIMETRACKER_MIN_GLPI,
                'max' => PLUGIN_TIMETRACKER_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_timetracker_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_TIMETRACKER_MIN_GLPI, '<')) {
        echo sprintf(__tt('This plugin requires GLPI >= %s.'), PLUGIN_TIMETRACKER_MIN_GLPI);
        return false;
    }

    if (version_compare(GLPI_VERSION, PLUGIN_TIMETRACKER_MAX_GLPI, '>=')) {
        echo sprintf(__tt('This plugin is not yet validated for GLPI >= %s.'), PLUGIN_TIMETRACKER_MAX_GLPI);
        return false;
    }

    if (version_compare(PHP_VERSION, '8.2', '<')) {
        echo __tt('This plugin requires PHP >= 8.2.');
        return false;
    }

    return true;
}

function plugin_timetracker_check_config($verbose = false): bool
{
    return true;
}

function __tt(string $text): string
{
    if (!function_exists('__')) {
        return $text;
    }

    return __($text, 'timetracker');
}
