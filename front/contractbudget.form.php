<?php

include('../../../inc/includes.php');

Session::checkRight('contract', UPDATE);

$contracts_id = (int) ($_POST['contracts_id'] ?? 0);

if ($contracts_id > 0 && isset($_POST['update'])) {
    if (PluginTimetrackerContractBudget::upsertForContract($contracts_id, $_POST)) {
        Session::addMessageAfterRedirect(__('Budget saved.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(__('Unable to save budget.', 'timetracker'), false, ERROR);
    }
}

Html::back();

