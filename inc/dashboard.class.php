<?php

class PluginTimetrackerDashboard extends CommonGLPI
{
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return __('Contract time dashboard', 'timetracker');
    }

    public static function getMenuName()
    {
        return __('Contract time', 'timetracker');
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => PluginTimetrackerContractBudget::getPluginWebDir() . '/front/dashboard.php',
            'icon'  => 'ti ti-clock-hour-4',
        ];
    }

    public static function showDashboard(): void
    {
        global $DB;

        $budget_table = PluginTimetrackerContractBudget::getTable();
        $entry_table = PluginTimetrackerTimeEntry::getTable();

        $iterator = $DB->request([
            'FROM'  => $budget_table,
            'ORDER' => 'contracts_id',
        ]);

        $contract = new Contract();
        $total_initial = 0;
        $total_spent = 0;

        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='7'>" . __('Contract time dashboard', 'timetracker') . '</th></tr>';
        echo '<tr>';
        echo '<th>' . __('Contract') . '</th>';
        echo '<th>' . __('Active') . '</th>';
        echo '<th>' . __('Initial time', 'timetracker') . '</th>';
        echo '<th>' . __('Consumed time', 'timetracker') . '</th>';
        echo '<th>' . __('Remaining time', 'timetracker') . '</th>';
        echo '<th>' . __('Alert threshold', 'timetracker') . '</th>';
        echo '<th>' . __('Status') . '</th>';
        echo '</tr>';

        $has_rows = false;
        foreach ($iterator as $budget) {
            $has_rows = true;
            $contracts_id = (int) $budget['contracts_id'];
            $spent = PluginTimetrackerContractBudget::getSpentMinutes($contracts_id);
            $remaining = (int) $budget['initial_minutes'] - $spent;
            $status = PluginTimetrackerContractBudget::getUsageStatus($budget);
            $total_initial += (int) $budget['initial_minutes'];
            $total_spent += $spent;

            $contract_name = sprintf('#%d', $contracts_id);
            if ($contract->getFromDB($contracts_id)) {
                $contract_name = $contract->getLink();
            }

            echo "<tr class='tab_bg_1'>";
            echo '<td>' . $contract_name . '</td>';
            echo '<td>' . Dropdown::getYesNo((int) $budget['is_active']) . '</td>';
            echo '<td>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $budget['initial_minutes'])) . '</td>';
            echo '<td>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes($spent)) . '</td>';
            echo '<td><strong class="' . self::getStatusCssClass($status) . '">' . htmlescape(PluginTimetrackerContractBudget::formatMinutes($remaining)) . '</strong></td>';
            echo '<td>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $budget['alert_threshold_minutes'])) . '</td>';
            echo '<td>' . htmlescape(self::getStatusLabel($status)) . '</td>';
            echo '</tr>';
        }

        if (!$has_rows) {
            echo "<tr class='tab_bg_1'><td colspan='7' class='center'>" . __('No item found') . '</td></tr>';
        }

        echo "<tr class='tab_bg_2'>";
        echo '<td colspan="2"><strong>' . __('Total') . '</strong></td>';
        echo '<td><strong>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_initial)) . '</strong></td>';
        echo '<td><strong>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_spent)) . '</strong></td>';
        echo '<td><strong>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_initial - $total_spent)) . '</strong></td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        echo '</table>';

        self::displayRecentEntries($entry_table);
        echo '</div>';
    }

    private static function displayRecentEntries(string $entry_table): void
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => $entry_table,
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['spent_at DESC', 'id DESC'],
            'LIMIT' => 10,
        ]);

        $ticket = new Ticket();
        $contract = new Contract();
        $user = new User();

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='5'>" . __('Latest time entries', 'timetracker') . '</th></tr>';
        echo '<tr><th>' . __('Date') . '</th><th>' . __('Ticket') . '</th><th>' . __('Contract') . '</th><th>' . __('User') . '</th><th>' . __('Duration') . '</th></tr>';

        $has_rows = false;
        foreach ($iterator as $entry) {
            $has_rows = true;
            $ticket_label = sprintf('#%d', (int) $entry['tickets_id']);
            if ($ticket->getFromDB((int) $entry['tickets_id'])) {
                $ticket_label = $ticket->getLink();
            }

            $contract_label = sprintf('#%d', (int) $entry['contracts_id']);
            if ($contract->getFromDB((int) $entry['contracts_id'])) {
                $contract_label = $contract->getLink();
            }

            $user_label = '';
            if ($user->getFromDB((int) $entry['users_id'])) {
                $user_label = $user->getLink();
            }

            echo "<tr class='tab_bg_1'>";
            echo '<td>' . htmlescape((string) $entry['spent_at']) . '</td>';
            echo '<td>' . $ticket_label . '</td>';
            echo '<td>' . $contract_label . '</td>';
            echo '<td>' . $user_label . '</td>';
            echo '<td>' . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $entry['duration_minutes'])) . '</td>';
            echo '</tr>';
        }

        if (!$has_rows) {
            echo "<tr class='tab_bg_1'><td colspan='5' class='center'>" . __('No item found') . '</td></tr>';
        }

        echo '</table>';
    }

    private static function getStatusLabel(string $status): string
    {
        if ($status === 'danger') {
            return __('Over budget', 'timetracker');
        }

        if ($status === 'warning') {
            return __('Threshold reached', 'timetracker');
        }

        return __('OK');
    }

    private static function getStatusCssClass(string $status): string
    {
        if ($status === 'danger') {
            return 'text-danger';
        }

        if ($status === 'warning') {
            return 'text-warning';
        }

        return 'text-success';
    }
}
