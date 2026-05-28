<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $km_rate   = (int) ($_POST['km_rate_cents'] ?? PluginTimetrackerTravelEntry::DEFAULT_KM_RATE_CENTS);
    $tech_rate = (int) ($_POST['tech_hourly_rate_cents'] ?? PluginTimetrackerUserRate::DEFAULT_TECH_HOURLY_RATE_CENTS);
    Config::setConfigurationValues('plugin:timetracker', [
        'km_rate_cents'          => max(0, $km_rate),
        'tech_hourly_rate_cents' => max(0, $tech_rate),
    ]);
    Session::addMessageAfterRedirect(__('Configuration saved.', 'timetracker'));
    Html::back();
}

Html::header(
    __tt('Contract time tracking — Configuration'),
    '',
    'config',
    'PluginTimetrackerDashboard'
);

$conf      = Config::getConfigurationValues('plugin:timetracker');
$km_rate   = (int) ($conf['km_rate_cents'] ?? PluginTimetrackerTravelEntry::DEFAULT_KM_RATE_CENTS);
$tech_rate = (int) ($conf['tech_hourly_rate_cents'] ?? PluginTimetrackerUserRate::DEFAULT_TECH_HOURLY_RATE_CENTS);

echo "<div class='p-3'>";
echo "<form method='post' action='" . htmlescape($_SERVER['REQUEST_URI']) . "'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>" . __tt('Plugin configuration') . "</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td class='p-3'>" . __tt('Default km rate (cents)') . "</td>";
echo "<td class='p-3'><input type='number' min='0' name='km_rate_cents' value='"
    . htmlescape((string) $km_rate) . "' class='form-control' style='width:160px'></td>";
echo "</tr>";
echo "<tr class='tab_bg_1'>";
echo "<td class='p-3'>" . __tt('Default technician hourly rate (cents)') . "</td>";
echo "<td class='p-3'><input type='number' min='0' name='tech_hourly_rate_cents' value='"
    . htmlescape((string) $tech_rate) . "' class='form-control' style='width:160px'></td>";
echo "</tr>";
echo "<tr><td colspan='2' class='p-3 text-end'>";
echo "<button type='submit' name='save' class='btn btn-primary'>"
    . htmlescape(_x('button', 'Save')) . "</button>";
echo "</td></tr>";
echo "</table>";
Html::closeForm();
echo "</div>";

Html::footer();
