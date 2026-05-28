<?php

class PluginTimetrackerTravelEntry extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'ticket';

    public const DEFAULT_KM_RATE_CENTS = 73;

    public static function getTypeName($nb = 0)
    {
        return _n('Travel entry', 'Travel entries', $nb, 'timetracker');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_timetracker_travelentries';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === Ticket::getType() && Ticket::canView()) {
            return "<span class='d-flex align-items-center gap-1'><i class='ti ti-car'></i>" . __tt('Travel') . "</span>";
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

    public static function getKmRateCents(?int $contracts_id = null): int
    {
        if ($contracts_id !== null && $contracts_id > 0) {
            $budget = PluginTimetrackerContractBudget::getForContract($contracts_id);
            if ($budget !== null && isset($budget['km_rate_cents']) && $budget['km_rate_cents'] !== null) {
                return max(0, (int) $budget['km_rate_cents']);
            }
        }

        $conf = Config::getConfigurationValues('plugin:timetracker');
        if (isset($conf['km_rate_cents']) && is_numeric($conf['km_rate_cents'])) {
            return max(0, (int) $conf['km_rate_cents']);
        }

        return self::DEFAULT_KM_RATE_CENTS;
    }

    public static function formatCost(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    public static function formatKm(float $km): string
    {
        return number_format($km, 2, ',', ' ') . ' km';
    }

    public static function addFromTicketForm(array $input): bool
    {
        $tickets_id   = (int) ($input['tickets_id'] ?? 0);
        $contracts_id = (int) ($input['contracts_id'] ?? 0);
        $km           = self::parseKm($input['km'] ?? 0);

        if ($tickets_id <= 0 || $contracts_id <= 0 || $km <= 0) {
            return false;
        }

        $budget = PluginTimetrackerContractBudget::getForContract($contracts_id);
        if ($budget === null || (int) $budget['is_active'] !== 1) {
            return false;
        }

        $travel_date = trim((string) ($input['travel_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $travel_date)) {
            $travel_date = date('Y-m-d');
        }

        $time_on_site = PluginTimetrackerTimeEntry::parseDurationMinutes([
            'duration_value' => $input['time_on_site_value'] ?? 0,
            'duration_unit'  => $input['time_on_site_unit'] ?? 'minutes',
        ]);

        $entry = new self();
        return (bool) $entry->add([
            'tickets_id'           => $tickets_id,
            'contracts_id'         => $contracts_id,
            'users_id'             => Session::getLoginUserID() ?: 0,
            'travel_date'          => $travel_date,
            'km'                   => $km,
            'time_on_site_minutes' => $time_on_site,
            'from_location'        => trim((string) ($input['from_location'] ?? '')),
            'purpose'              => trim((string) ($input['purpose'] ?? '')),
            'comment'              => (string) ($input['comment'] ?? ''),
            'is_deleted'           => 0,
        ]);
    }

    public static function parseKm(mixed $raw): float
    {
        $value = str_replace(',', '.', (string) $raw);
        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    public static function getTicketEntries(int $tickets_id): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'tickets_id' => $tickets_id,
                'is_deleted' => 0,
            ],
            'ORDER' => ['travel_date DESC', 'id DESC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getTicketTotals(int $tickets_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                new QueryExpression('SUM(km) AS total_km'),
                new QueryExpression('SUM(time_on_site_minutes) AS total_minutes'),
                new QueryExpression('COUNT(*) AS total_count'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'is_deleted' => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            return [
                'km'      => (float) ($row['total_km'] ?? 0),
                'minutes' => (int) ($row['total_minutes'] ?? 0),
                'count'   => (int) ($row['total_count'] ?? 0),
            ];
        }

        return ['km' => 0.0, 'minutes' => 0, 'count' => 0];
    }

    public static function getContractTotals(int $contracts_id): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                new QueryExpression('SUM(km) AS total_km'),
                new QueryExpression('SUM(time_on_site_minutes) AS total_minutes'),
                new QueryExpression('COUNT(*) AS total_count'),
            ],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'contracts_id' => $contracts_id,
                'is_deleted'   => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            return [
                'km'      => (float) ($row['total_km'] ?? 0),
                'minutes' => (int) ($row['total_minutes'] ?? 0),
                'count'   => (int) ($row['total_count'] ?? 0),
            ];
        }

        return ['km' => 0.0, 'minutes' => 0, 'count' => 0];
    }

    private static function showTicketTab(int $tickets_id): void
    {
        $totals   = self::getTicketTotals($tickets_id);
        $entries  = self::getTicketEntries($tickets_id);
        $contracts_id_for_rate = (int) ($entries[0]['contracts_id'] ?? 0);
        $rate     = self::getKmRateCents($contracts_id_for_rate);

        $rate_cache = [];
        $cost_cent  = 0;
        foreach ($entries as $entry) {
            $entry_cid  = (int) $entry['contracts_id'];
            $entry_rate = $rate_cache[$entry_cid] ?? ($rate_cache[$entry_cid] = self::getKmRateCents($entry_cid));
            $cost_cent += (int) round(((float) $entry['km']) * $entry_rate);
        }

        echo "<div class='p-3'>";

        // Totals
        echo "<div class='mb-4'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . __tt('Travel summary for this ticket') . '</th></tr>';
        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'>" . __tt('Total distance') . '</td>';
        echo "<td class='p-3'><strong>" . htmlescape(self::formatKm((float) $totals['km'])) . '</strong></td>';
        echo "<td class='p-3'>" . __tt('Estimated cost') . sprintf(
            ' <small class="text-muted">(@ %s)</small>',
            htmlescape(self::formatCost($rate) . '/km')
        ) . '</td>';
        echo "<td class='p-3'><strong>" . htmlescape(self::formatCost($cost_cent)) . '</strong></td>';
        echo '</tr>';
        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'>" . __tt('Time on site') . '</td>';
        echo "<td class='p-3'><strong>"
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $totals['minutes']))
            . '</strong></td>';
        echo "<td class='p-3'>" . __tt('Number of trips') . '</td>';
        echo "<td class='p-3'><strong>" . (int) $totals['count'] . '</strong></td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';

        if (Ticket::canUpdate()) {
            self::showAddForm($tickets_id);
        }

        self::showTicketEntries($tickets_id);
        echo '</div>';
    }

    private static function showAddForm(int $tickets_id): void
    {
        $default_contracts_id = PluginTimetrackerContractBudget::getSuggestedContractForTicket($tickets_id);

        echo "<div class='mb-4'>";
        echo "<form method='post' action='"
            . htmlescape(PluginTimetrackerContractBudget::getPluginWebDir() . '/front/travelentry.form.php') . "'>";
        echo Html::hidden('tickets_id', ['value' => $tickets_id]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='4'>" . __tt('Add travel') . '</th></tr>';

        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Contract') . "</label><br>";
        PluginTimetrackerContractBudget::dropdownConfiguredContracts([
            'name'                => 'contracts_id',
            'value'               => $default_contracts_id,
            'display_emptychoice' => true,
        ]);
        echo '</td>';
        echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Travel date') . "</label><br>";
        echo "<input type='date' name='travel_date' class='form-control'"
            . " value='" . htmlescape(date('Y-m-d')) . "'>";
        echo '</td>';
        echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Distance (km)') . "</label><br>";
        echo "<input type='number' min='0' step='0.1' name='km' class='form-control' style='width:140px' value='0'>";
        echo '</td>';
        echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Time on site') . "</label><br>";
        echo "<div class='d-flex gap-2 align-items-center'>";
        echo "<input type='number' min='0' step='0.25' name='time_on_site_value'"
            . " class='form-control' style='width:100px' value='0'>";
        Dropdown::showFromArray('time_on_site_unit', [
            'minutes' => __tt('Minutes'),
            'hours'   => __tt('Hours'),
        ], ['value' => 'minutes']);
        echo '</div></td>';
        echo '</tr>';

        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('From (origin site)') . "</label><br>";
        echo "<input type='text' name='from_location' class='form-control' value=''>";
        echo '</td>';
        echo "<td class='p-3' colspan='3'><label class='form-label fw-semibold'>" . __tt('Purpose / reason') . "</label><br>";
        echo "<input type='text' name='purpose' class='form-control' value=''>";
        echo '</td>';
        echo '</tr>';

        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3' colspan='4'><label class='form-label fw-semibold'>" . __('Comments') . "</label><br>";
        echo "<textarea name='comment' class='form-control' rows='2'></textarea>";
        echo '</td>';
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

    private static function showTicketEntries(int $tickets_id): void
    {
        $entries  = self::getTicketEntries($tickets_id);
        $contract = new Contract();
        $user     = new User();

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='9'>" . __tt('Travel history') . '</th></tr>';
        echo '<tr>';
        echo '<th class="p-2">' . __('Date') . '</th>';
        echo '<th class="p-2">' . __('Contract') . '</th>';
        echo '<th class="p-2">' . __('User') . '</th>';
        echo '<th class="p-2">' . __tt('From') . '</th>';
        echo '<th class="p-2">' . __tt('Distance') . '</th>';
        echo '<th class="p-2">' . __tt('Time on site') . '</th>';
        echo '<th class="p-2">' . __tt('Cost') . '</th>';
        echo '<th class="p-2">' . __tt('Purpose') . '</th>';
        echo '<th class="p-2"></th>';
        echo '</tr>';

        if ($entries === []) {
            echo "<tr class='tab_bg_1'><td colspan='9' class='center p-3'>" . __('No item found') . '</td></tr>';
            echo '</table>';
            return;
        }

        $rate_cache = [];
        foreach ($entries as $entry) {
            $contract_name = '';
            if ($contract->getFromDB((int) $entry['contracts_id'])) {
                $contract_name = $contract->getName();
            }

            $user_name = '';
            if ($user->getFromDB((int) $entry['users_id'])) {
                $user_name = $user->getName();
            }

            $km          = (float) $entry['km'];
            $entry_cid   = (int) $entry['contracts_id'];
            $entry_rate  = $rate_cache[$entry_cid] ?? ($rate_cache[$entry_cid] = self::getKmRateCents($entry_cid));
            $cost_cent   = (int) round($km * $entry_rate);

            echo "<tr class='tab_bg_1'>";
            echo '<td class="p-2">' . htmlescape((string) $entry['travel_date']) . '</td>';
            echo '<td class="p-2">' . htmlescape($contract_name) . '</td>';
            echo '<td class="p-2">' . htmlescape($user_name) . '</td>';
            echo '<td class="p-2">' . htmlescape((string) $entry['from_location']) . '</td>';
            echo '<td class="p-2">' . htmlescape(self::formatKm($km)) . '</td>';
            echo '<td class="p-2">'
                . htmlescape(PluginTimetrackerContractBudget::formatMinutes((int) $entry['time_on_site_minutes']))
                . '</td>';
            echo '<td class="p-2">' . htmlescape(self::formatCost($cost_cent)) . '</td>';
            echo '<td class="p-2">' . htmlescape((string) $entry['purpose']) . '</td>';
            echo '<td class="p-2 text-center">';
            if (Ticket::canUpdate()) {
                echo "<form method='post' action='"
                    . htmlescape(PluginTimetrackerContractBudget::getPluginWebDir() . '/front/travelentry.form.php') . "'>";
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
