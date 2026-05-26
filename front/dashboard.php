<?php

include('../../../inc/includes.php');

Session::checkRight('contract', READ);

Html::header(
    __('Contract time dashboard', 'timetracker'),
    $_SERVER['PHP_SELF'],
    'tools',
    PluginTimetrackerDashboard::class
);

PluginTimetrackerDashboard::showDashboard();

Html::footer();
