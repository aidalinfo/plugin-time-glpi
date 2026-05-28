<?php

include('../../../inc/includes.php');

Session::checkRight('ticket', UPDATE);

$tickets_id = (int) ($_POST['tickets_id'] ?? 0);

if (isset($_POST['add'])) {
    if (PluginTimetrackerTravelEntry::addFromTicketForm($_POST)) {
        Session::addMessageAfterRedirect(__('Travel entry added.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(
            __('Contract and distance are required.', 'timetracker'),
            false,
            ERROR
        );
    }
}

if (isset($_POST['delete'])) {
    $entry = new PluginTimetrackerTravelEntry();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0 && $entry->delete(['id' => $id], true)) {
        Session::addMessageAfterRedirect(__('Travel entry deleted.', 'timetracker'));
    } else {
        Session::addMessageAfterRedirect(__('Unable to delete travel entry.', 'timetracker'), false, ERROR);
    }
}

if ($tickets_id > 0) {
    Html::redirect(Ticket::getFormURLWithID($tickets_id));
}

Html::back();
