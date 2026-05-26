<?php

class PluginTimetrackerTimeEntry extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0)
    {
        return _n('Contract time entry', 'Contract time entries', $nb, 'timetracker');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_timetracker_timeentries';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === Ticket::getType() && Ticket::canView()) {
            return "<i class='ti ti-file-time me-1'></i>" . __tt('Contract time');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Ticket::getType()) {
            self::showTicketTab((int) $item->getID());
        }

        return true;
    }

    public static function addFromTicketForm(array $input): bool
    {
        $tickets_id = (int) ($input['tickets_id'] ?? 0);
        $contracts_id = (int) ($input['contracts_id'] ?? 0);
        $duration_minutes = self::parseDurationMinutes($input);

        if ($tickets_id <= 0 || $contracts_id <= 0 || $duration_minutes <= 0) {
            return false;
        }

        $budget = PluginTimetrackerContractBudget::getForContract($contracts_id);
        if ($budget === null || (int) $budget['is_active'] !== 1) {
            return false;
        }

        $entry = new self();
        $spent_at = trim((string) ($input['spent_at'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $spent_at)) {
            $spent_at = date('Y-m-d');
        }

        return (bool) $entry->add([
            'tickets_id'        => $tickets_id,
            'contracts_id'      => $contracts_id,
            'users_id'          => Session::getLoginUserID() ?: 0,
            'duration_minutes'  => $duration_minutes,
            'spent_at'          => $spent_at,
            'comment'           => $input['comment'] ?? '',
            'is_deleted'        => 0,
        ]);
    }

    public static function parseDurationMinutes(array $input): int
    {
        $raw_value = str_replace(',', '.', (string) ($input['duration_value'] ?? 0));
        $value = is_numeric($raw_value) ? (float) $raw_value : 0.0;
        $unit = (string) ($input['duration_unit'] ?? 'minutes');

        if ($unit === 'hours') {
            return max(0, (int) round($value * 60));
        }

        return max(0, (int) round($value));
    }

    public static function getTicketEntries(int $tickets_id): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'tickets_id'  => $tickets_id,
                'is_deleted'  => 0,
            ],
            'ORDER' => ['spent_at DESC', 'id DESC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getTicketTotalMinutes(int $tickets_id): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [new QueryExpression('SUM(duration_minutes) AS total')],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'is_deleted' => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            return (int) ($row['total'] ?? 0);
        }

        return 0;
    }

    private static function showTicketTab(int $tickets_id): void
    {
        echo "<div class='p-3'>";

        // Bloc total
        echo "<div class='mb-4'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . __tt('Contract time spent on this ticket') . '</th></tr>';
        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'>" . __tt('Total') . '</td>';
        echo "<td colspan='3' class='p-3'><strong>"
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes(self::getTicketTotalMinutes($tickets_id)))
            . '</strong></td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';

        if (Ticket::canUpdate()) {
            $default_contracts_id = PluginTimetrackerContractBudget::getSuggestedContractForTicket($tickets_id);

            echo "<div class='mb-4'>";
            echo "<form method='post' action='"
                . htmlescape(PluginTimetrackerContractBudget::getPluginWebDir() . '/front/timeentry.form.php') . "'>";
            echo Html::hidden('tickets_id', ['value' => $tickets_id]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __tt('Add spent time') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Contract') . "</label><br>";
            PluginTimetrackerContractBudget::dropdownConfiguredContracts([
                'name'                => 'contracts_id',
                'value'               => $default_contracts_id,
                'display_emptychoice' => true,
            ]);
            echo "</td>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Spent on') . "</label><br>";
            echo "<input type='date' name='spent_at' class='form-control'"
                . " value='" . htmlescape(date('Y-m-d')) . "'>";
            echo "</td>";
            echo '</tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Duration') . "</label><br>";
            echo "<div class='d-flex gap-2 align-items-center'>";
            echo "<input type='number' min='0' step='0.25' name='duration_value'"
                . " class='form-control' style='width:100px' value='0'>";
            Dropdown::showFromArray('duration_unit', [
                'minutes' => __tt('Minutes'),
                'hours'   => __tt('Hours'),
            ], ['value' => 'minutes']);
            echo "</div></td>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Comments') . "</label><br>";
            echo "<textarea name='comment' class='form-control'></textarea>";
            echo "</td>";
            echo '</tr>';
            echo "<tr><td colspan='4' class='p-3 text-end'>";
            echo "<button type='submit' name='add' class='btn btn-primary'>";
            echo "<i class='ti ti-plus me-1'></i>" . htmlescape(_x('button', 'Add'));
            echo "</button>";
            echo '</td></tr>';
            echo '</table>';
            Html::closeForm();
            echo '</div>';
        }

        self::showTicketEntries($tickets_id);
        echo '</div>';
    }

    private static function showTicketEntries(int $tickets_id): void
    {
        $entries  = self::getTicketEntries($tickets_id);
        $contract = new Contract();
        $user     = new User();

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='6'>" . __tt('Time history') . '</th></tr>';
        echo '<tr>';
        echo '<th class="p-2">' . __('Date') . '</th>';
        echo '<th class="p-2">' . __('Contract') . '</th>';
        echo '<th class="p-2">' . __('User') . '</th>';
        echo '<th class="p-2">' . __('Duration') . '</th>';
        echo '<th class="p-2">' . __('Comments') . '</th>';
        echo '<th class="p-2"></th>';
        echo '</tr>';

        if ($entries === []) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center p-3'>" . __('No item found') . '</td></tr>';
            echo '</table>';
            return;
        }

        foreach ($entries as $entry) {
            $contract_name = '';
            if ($contract->getFromDB((int) $entry['contracts_id'])) {
                $contract_name = $contract->getName();
            }

            $user_name = '';
            if ($user->getFromDB((int) $entry['users_id'])) {
                $user_name = $user->getName();
            }

            echo "<tr class='tab_bg_1'>";
            echo '<td class="p-2">' . htmlescape((string) $entry['spent_at']) . '</td>';
            echo '<td class="p-2">' . htmlescape($contract_name) . '</td>';
            echo '<td class="p-2">' . htmlescape($user_name) . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $entry['duration_minutes']))
                . '</td>';
            echo '<td class="p-2">' . nl2br(htmlescape((string) $entry['comment'])) . '</td>';
            echo '<td class="p-2 text-center">';
            if (Ticket::canUpdate()) {
                echo "<form method='post' action='"
                    . htmlescape(PluginTimetrackerContractBudget::getPluginWebDir() . '/front/timeentry.form.php') . "'>";
                echo Html::hidden('id', ['value' => (int) $entry['id']]);
                echo Html::hidden('tickets_id', ['value' => $tickets_id]);
                echo "<button type='submit' name='delete' class='btn btn-sm btn-outline-danger'"
                    . " title='" . htmlescape(_x('button', 'Delete permanently')) . "'>"
                    . "<i class='ti ti-trash'></i></button>";
                Html::closeForm();
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }
}
