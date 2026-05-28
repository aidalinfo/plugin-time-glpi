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
        $entry_table  = PluginTimetrackerTimeEntry::getTable();

        $iterator = $DB->request([
            'FROM'  => $budget_table,
            'ORDER' => 'contracts_id',
        ]);

        $contract      = new Contract();
        $total_initial = 0;
        $total_spent   = 0;

        $base = PluginTimetrackerContractBudget::getPluginWebDir() . '/front/dashboard.php';

        echo "<div class='center p-3'>";
        echo "<div class='mb-3 d-flex gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '?export=time') . "'>"
            . "<i class='ti ti-download me-1'></i>" . htmlescape(__tt('Export time CSV')) . "</a>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '?export=travel') . "'>"
            . "<i class='ti ti-download me-1'></i>" . htmlescape(__tt('Export travel CSV')) . "</a>";
        echo "</div>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='11'>" . __tt('Contract time dashboard') . '</th></tr>';
        echo '<tr>';
        echo '<th class="p-2">' . __('Contract') . '</th>';
        echo '<th class="p-2">' . __('Active') . '</th>';
        echo '<th class="p-2">' . __tt('Initial time') . '</th>';
        echo '<th class="p-2">' . __tt('Consumed time') . '</th>';
        echo '<th class="p-2">' . __tt('Remaining time') . '</th>';
        echo '<th class="p-2">' . __tt('Alert threshold') . '</th>';
        echo '<th class="p-2">' . __('Status') . '</th>';
        echo '<th class="p-2">' . __tt('Travel km') . '</th>';
        echo '<th class="p-2">' . __tt('Travel cost') . '</th>';
        echo '<th class="p-2">' . __tt('Projection') . '</th>';
        echo '<th class="p-2">' . __tt('Over budget?') . '</th>';
        echo '</tr>';

        $has_rows = false;
        foreach ($iterator as $budget) {
            $has_rows      = true;
            $contracts_id  = (int) $budget['contracts_id'];
            $spent         = PluginTimetrackerContractBudget::getSpentMinutes($contracts_id);
            $remaining     = (int) $budget['initial_minutes'] - $spent;
            $status        = PluginTimetrackerContractBudget::getUsageStatus($budget);
            $travel        = PluginTimetrackerTravelEntry::getContractTotals($contracts_id);
            $rate          = PluginTimetrackerTravelEntry::getKmRateCents($contracts_id);
            $cost          = (int) round($travel['km'] * $rate);
            $proj          = PluginTimetrackerContractBudget::getProjection($contracts_id);
            $projected_total = (int) $proj['projected_total_minutes'];
            $over_projection = $projected_total > (int) $budget['initial_minutes'];
            $total_initial += (int) $budget['initial_minutes'];
            $total_spent   += $spent;
            $pct           = $budget['initial_minutes'] > 0
                ? min(100, (int) round($spent / $budget['initial_minutes'] * 100))
                : 0;

            $bar_class   = match ($status) {
                'danger'  => 'bg-danger',
                'warning' => 'bg-warning',
                default   => 'bg-success',
            };
            $badge_class = match ($status) {
                'danger'  => 'bg-danger',
                'warning' => 'bg-warning text-dark',
                default   => 'bg-success',
            };

            $contract_name = sprintf('#%d', $contracts_id);
            if ($contract->getFromDB($contracts_id)) {
                $contract_name = $contract->getLink();
            }

            echo "<tr class='tab_bg_1'>";
            echo '<td class="p-2">' . $contract_name . '</td>';
            echo '<td class="p-2">' . Dropdown::getYesNo((int) $budget['is_active']) . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $budget['initial_minutes']))
                . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes($spent))
                . '</td>';
            echo '<td class="p-2"><strong class="' . self::getStatusCssClass($status) . '">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes($remaining))
                . '</strong></td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $budget['alert_threshold_minutes']))
                . '</td>';
            echo '<td class="p-2"><span class="badge ' . $badge_class . '">'
                . htmlescape(self::getStatusLabel($status)) . '</span></td>';
            echo '<td class="p-2">' . htmlescape(PluginTimetrackerTravelEntry::formatKm((float) $travel['km'])) . '</td>';
            echo '<td class="p-2">' . htmlescape(PluginTimetrackerTravelEntry::formatCost($cost)) . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes($projected_total)) . '</td>';
            echo '<td class="p-2 text-center">'
                . ($over_projection
                    ? '<i class="ti ti-alert-triangle text-warning" title="' . htmlescape(__tt('Projected over budget')) . '"></i>'
                    : '<i class="ti ti-check text-success"></i>') . '</td>';
            echo '</tr>';
            echo "<tr class='tab_bg_1'><td colspan='11' class='px-3 pb-2'>";
            echo "<div class='progress' style='height:8px'>";
            echo "<div class='progress-bar {$bar_class}' role='progressbar' style='width:{$pct}%'></div>";
            echo "</div>";
            echo '</td></tr>';
        }

        if (!$has_rows) {
            echo "<tr class='tab_bg_1'><td colspan='11' class='center p-3'>"
                . __('No item found') . '</td></tr>';
        }

        echo "<tr class='table-secondary'>";
        echo '<td colspan="2" class="p-2"><strong>' . __('Total') . '</strong></td>';
        echo '<td class="p-2"><strong>'
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_initial))
            . '</strong></td>';
        echo '<td class="p-2"><strong>'
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_spent))
            . '</strong></td>';
        echo '<td class="p-2"><strong>'
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes($total_initial - $total_spent))
            . '</strong></td>';
        echo '<td colspan="6"></td>';
        echo '</tr>';
        echo '</table>';

        echo "<div class='mb-4'></div>";
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

        $ticket   = new Ticket();
        $contract = new Contract();
        $user     = new User();

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='5'>" . __tt('Latest time entries') . '</th></tr>';
        echo '<tr>';
        echo '<th class="p-2">' . __('Date') . '</th>';
        echo '<th class="p-2">' . __('Ticket') . '</th>';
        echo '<th class="p-2">' . __('Contract') . '</th>';
        echo '<th class="p-2">' . __('User') . '</th>';
        echo '<th class="p-2">' . __('Duration') . '</th>';
        echo '</tr>';

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
            echo '<td class="p-2">' . htmlescape((string) $entry['spent_at']) . '</td>';
            echo '<td class="p-2">' . $ticket_label . '</td>';
            echo '<td class="p-2">' . $contract_label . '</td>';
            echo '<td class="p-2">' . $user_label . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $entry['duration_minutes']))
                . '</td>';
            echo '</tr>';
        }

        if (!$has_rows) {
            echo "<tr class='tab_bg_1'><td colspan='5' class='center p-3'>"
                . __('No item found') . '</td></tr>';
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
