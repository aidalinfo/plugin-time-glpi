<?php

include('../../../inc/includes.php');

Session::checkRight('contract', UPDATE);

$contracts_id = (int) ($_POST['contracts_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($contracts_id <= 0) {
    Html::back();
}

if ($action === 'add') {
    $type = in_array($_POST['type'] ?? '', ['deadline', 'threshold'], true) ? $_POST['type'] : 'deadline';
    $days_before = $type === 'deadline' ? max(1, (int) ($_POST['days_before'] ?? 30)) : null;
    $recipient_email = trim($_POST['recipient_email'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($recipient_email !== '' && filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        $alert = new PluginTimetrackerAlertConfig();
        $alert->add([
            'contracts_id'    => $contracts_id,
            'type'            => $type,
            'days_before'     => $days_before,
            'recipient_email' => $recipient_email,
            'is_active'       => $is_active,
        ]);
        Session::addMessageAfterRedirect(__tt('Alert added.'));
    } else {
        Session::addMessageAfterRedirect(__tt('Invalid email address.'), false, ERROR);
    }
} elseif ($action === 'delete') {
    $alerts_id = (int) ($_POST['alerts_id'] ?? 0);
    if ($alerts_id > 0) {
        $alert = new PluginTimetrackerAlertConfig();
        $alert->delete(['id' => $alerts_id], true);
        Session::addMessageAfterRedirect(__tt('Alert deleted.'));
    }
}

Html::redirect(Contract::getFormURLWithID($contracts_id));
