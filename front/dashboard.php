<?php

include('../../../inc/includes.php');

Session::checkRight('contract', READ);

$action = $_GET['export'] ?? '';
$contracts_id = isset($_GET['contracts_id']) && $_GET['contracts_id'] !== ''
    ? (int) $_GET['contracts_id']
    : null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;

if ($action === 'time') {
    PluginTimetrackerExporter::streamTimeEntriesCsv($contracts_id, $date_from, $date_to);
    exit;
}
if ($action === 'travel') {
    PluginTimetrackerExporter::streamTravelEntriesCsv($contracts_id, $date_from, $date_to);
    exit;
}

Html::header(
    __('Contract time dashboard', 'timetracker'),
    $_SERVER['PHP_SELF'],
    'tools',
    PluginTimetrackerDashboard::class
);

PluginTimetrackerDashboard::showDashboard($contracts_id);

Html::footer();
