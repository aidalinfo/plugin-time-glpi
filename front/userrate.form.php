<?php

include('../../../inc/includes.php');

Session::checkRight('user', UPDATE);

$users_id = (int) ($_POST['users_id'] ?? 0);

if ($users_id > 0 && isset($_POST['save'])) {
    if (PluginTimetrackerUserRate::upsertForUser($users_id, $_POST)) {
        Session::addMessageAfterRedirect(__('Hourly rate saved.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(__('Unable to save hourly rate.', 'timetracker'), false, ERROR);
    }
}

if ($users_id > 0) {
    Html::redirect(User::getFormURLWithID($users_id));
}

Html::back();
