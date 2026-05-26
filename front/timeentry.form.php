<?php

include('../../../inc/includes.php');

Session::checkRight('ticket', UPDATE);
Session::checkCSRF($_POST);

$tickets_id = (int) ($_POST['tickets_id'] ?? 0);

if (isset($_POST['add'])) {
    if (PluginTimetrackerTimeEntry::addFromTicketForm($_POST)) {
        Session::addMessageAfterRedirect(__('Time entry added.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(__('Contract and duration are required.', 'timetracker'), false, ERROR);
    }
}

if (isset($_POST['delete'])) {
    $entry = new PluginTimetrackerTimeEntry();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && $entry->delete(['id' => $id], true)) {
        Session::addMessageAfterRedirect(__('Time entry deleted.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(__('Unable to delete time entry.', 'timetracker'), false, ERROR);
    }
}

if ($tickets_id > 0) {
    Html::redirect(Ticket::getFormURLWithID($tickets_id));
}

Html::back();

