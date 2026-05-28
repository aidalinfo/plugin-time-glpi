<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$glpi_root = $argv[1] ?? null;
if ($glpi_root === null) {
    fwrite(STDERR, "Usage: php tools/smoke.php /path/to/glpi\n");
    exit(1);
}

$autoload = rtrim($glpi_root, '/') . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "GLPI autoload file not found: {$autoload}\n");
    exit(1);
}

$plugin_root = dirname(__DIR__);

require_once $plugin_root . '/setup.php';
require_once $autoload;
require_once $plugin_root . '/inc/contractbudget.class.php';
require_once $plugin_root . '/inc/timeentry.class.php';
require_once $plugin_root . '/inc/travelentry.class.php';
require_once $plugin_root . '/inc/dashboard.class.php';
require_once $plugin_root . '/hook.php';

$failures = [];

$version = plugin_version_timetracker();
if (($version['requirements']['glpi']['min'] ?? null) !== '11.0.0') {
    $failures[] = 'Unexpected GLPI minimum version.';
}
if (($version['requirements']['glpi']['max'] ?? null) !== '11.1.0') {
    $failures[] = 'Unexpected GLPI maximum version.';
}

$GLOBALS['PLUGIN_HOOKS'] = [];
plugin_init_timetracker();

if (!isset($GLOBALS['PLUGIN_HOOKS']['menu_toadd']['timetracker']['tools'])) {
    $failures[] = 'Tools menu hook is missing.';
}
if (!isset($GLOBALS['PLUGIN_HOOKS']['config_page']['timetracker'])) {
    $failures[] = 'Config page hook is missing.';
}

if (!function_exists('plugin_timetracker_install')) {
    $failures[] = 'Install hook is missing.';
}
if (!function_exists('plugin_timetracker_uninstall')) {
    $failures[] = 'Uninstall hook is missing.';
}

if (PluginTimetrackerContractBudget::parseDurationInput([
    'initial_value' => '2.5',
    'initial_unit'  => 'hours',
], 'initial') !== 150) {
    $failures[] = 'Contract duration conversion failed.';
}

if (PluginTimetrackerTimeEntry::parseDurationMinutes([
    'duration_value' => '1,25',
    'duration_unit'  => 'hours',
]) !== 75) {
    $failures[] = 'Ticket duration conversion failed.';
}

if (PluginTimetrackerContractBudget::formatMinutes(95) !== '1h 35min') {
    $failures[] = 'Minute formatting failed.';
}

$conf_before = Config::getConfigurationValues('plugin:timetracker');
Config::setConfigurationValues('plugin:timetracker', ['km_rate_cents' => 90]);
if (PluginTimetrackerTravelEntry::getKmRateCents(0) !== 90) {
    $failures[] = 'Global km rate fallback failed.';
}
Config::setConfigurationValues('plugin:timetracker', $conf_before);

require_once $plugin_root . '/inc/exporter.class.php';
require_once $plugin_root . '/inc/monthlyreport.class.php';

if (!method_exists(PluginTimetrackerExporter::class, 'streamTimeEntriesCsv')) {
    $failures[] = 'Missing CSV exporter for time entries.';
}
if (!method_exists(PluginTimetrackerExporter::class, 'streamTravelEntriesCsv')) {
    $failures[] = 'Missing CSV exporter for travel entries.';
}
if (!method_exists(PluginTimetrackerContractBudget::class, 'getProjection')) {
    $failures[] = 'Missing run-rate projection helper.';
}
if (!method_exists(PluginTimetrackerMonthlyReport::class, 'cronSendMonthlyReports')) {
    $failures[] = 'Missing monthly report cron entrypoint.';
}

require_once $plugin_root . '/inc/userrate.class.php';

if (!method_exists(PluginTimetrackerUserRate::class, 'getRateCents')) {
    $failures[] = 'Missing per-user rate helper.';
}
if (!method_exists(PluginTimetrackerContractBudget::class, 'getContractMarginCents')) {
    $failures[] = 'Missing contract margin helper.';
}

$conf_before2 = Config::getConfigurationValues('plugin:timetracker');
Config::setConfigurationValues('plugin:timetracker', ['tech_hourly_rate_cents' => 4200]);
if (PluginTimetrackerUserRate::getGlobalRateCents() !== 4200) {
    $failures[] = 'Global tech rate fallback failed.';
}
if (PluginTimetrackerUserRate::getRateCents(0) !== 4200) {
    $failures[] = 'Tech rate resolution for null user failed.';
}
Config::setConfigurationValues('plugin:timetracker', $conf_before2);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

echo "Smoke test OK\n";

