<?php

class PluginTimetrackerContractBudget extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return _n('Contract time budget', 'Contract time budgets', $nb, 'timetracker');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_timetracker_contractbudgets';
    }

    public static function getPluginWebDir(): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/timetracker';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === Contract::getType() && Contract::canView()) {
            return __tt('Time budget');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Contract::getType()) {
            self::showContractTab((int) $item->getID());
        }

        return true;
    }

    public static function getForContract(int $contracts_id): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_id],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    public static function upsertForContract(int $contracts_id, array $input): bool
    {
        $budget = new self();
        $existing = self::getForContract($contracts_id);
        $data = [
            'contracts_id'             => $contracts_id,
            'initial_minutes'          => self::parseDurationInput($input, 'initial'),
            'alert_threshold_minutes'  => self::parseDurationInput($input, 'alert'),
            'is_active'                => isset($input['is_active']) ? 1 : 0,
            'comment'                  => $input['comment'] ?? '',
        ];

        if ($existing !== null) {
            $data['id'] = (int) $existing['id'];
            return (bool) $budget->update($data);
        }

        return (bool) $budget->add($data);
    }

    public static function parseDurationInput(array $input, string $prefix): int
    {
        $legacy_key = $prefix === 'initial' ? 'initial_minutes' : 'alert_threshold_minutes';
        if (!array_key_exists($prefix . '_value', $input)) {
            return max(0, (int) ($input[$legacy_key] ?? 0));
        }

        $raw_value = str_replace(',', '.', (string) ($input[$prefix . '_value'] ?? 0));
        $value = is_numeric($raw_value) ? (float) $raw_value : 0.0;
        $unit = (string) ($input[$prefix . '_unit'] ?? 'minutes');

        if ($unit === 'hours') {
            return max(0, (int) round($value * 60));
        }

        return max(0, (int) round($value));
    }

    public static function getSpentMinutes(int $contracts_id): int
    {
        global $DB;

        $table = PluginTimetrackerTimeEntry::getTable();
        $result = $DB->request([
            'SELECT' => [new QueryExpression('SUM(duration_minutes) AS total')],
            'FROM'   => $table,
            'WHERE'  => [
                'contracts_id' => $contracts_id,
                'is_deleted'   => 0,
            ],
        ]);

        foreach ($result as $row) {
            return (int) ($row['total'] ?? 0);
        }

        return 0;
    }

    public static function getRemainingMinutes(int $contracts_id): int
    {
        $budget = self::getForContract($contracts_id);
        if ($budget === null) {
            return 0;
        }

        return (int) $budget['initial_minutes'] - self::getSpentMinutes($contracts_id);
    }

    public static function formatMinutes(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $absolute = abs($minutes);
        $hours = intdiv($absolute, 60);
        $mins = $absolute % 60;

        if ($hours > 0 && $mins > 0) {
            return sprintf('%s%dh %02dmin', $sign, $hours, $mins);
        }

        if ($hours > 0) {
            return sprintf('%s%dh', $sign, $hours);
        }

        return sprintf('%s%dmin', $sign, $mins);
    }

    public static function getUsageStatus(array $budget): string
    {
        $remaining = (int) $budget['initial_minutes'] - self::getSpentMinutes((int) $budget['contracts_id']);

        if ($remaining < 0) {
            return 'danger';
        }

        if ((int) $budget['alert_threshold_minutes'] > 0 && $remaining <= (int) $budget['alert_threshold_minutes']) {
            return 'warning';
        }

        return 'ok';
    }

    public static function dropdownConfiguredContracts(array $options = []): void
    {
        global $DB;

        $name = $options['name'] ?? 'contracts_id';
        $value = (int) ($options['value'] ?? 0);
        $display_emptychoice = $options['display_emptychoice'] ?? true;

        echo "<select name='" . htmlescape($name) . "' class='form-select'>";
        if ($display_emptychoice) {
            echo "<option value='0'>" . Dropdown::EMPTY_VALUE . "</option>";
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
            'ORDER' => 'contracts_id',
        ]);

        $contract = new Contract();
        foreach ($iterator as $budget) {
            $contracts_id = (int) $budget['contracts_id'];
            if ($contracts_id > 0 && $contract->getFromDB($contracts_id)) {
                $selected = $contracts_id === $value ? " selected='selected'" : '';
                echo "<option value='{$contracts_id}'{$selected}>" . htmlescape($contract->getName()) . "</option>";
            }
        }

        echo '</select>';
    }

    public static function getSuggestedContractForTicket(int $tickets_id): int
    {
        global $DB;

        if ($tickets_id <= 0) {
            return 0;
        }

        $iterator = $DB->request([
            'FROM'  => Ticket_Contract::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id],
            'ORDER' => 'contracts_id',
        ]);

        foreach ($iterator as $row) {
            $contracts_id = (int) $row['contracts_id'];
            $budget = self::getForContract($contracts_id);
            if ($budget !== null && (int) $budget['is_active'] === 1) {
                return $contracts_id;
            }
        }

        return 0;
    }

    private static function showContractTab(int $contracts_id): void
    {
        $budget = self::getForContract($contracts_id) ?? [
            'initial_minutes'         => 0,
            'alert_threshold_minutes' => 0,
            'is_active'               => 1,
            'comment'                 => '',
        ];

        $spent     = self::getSpentMinutes($contracts_id);
        $remaining = (int) $budget['initial_minutes'] - $spent;
        $status    = self::getUsageStatus($budget + ['contracts_id' => $contracts_id]);
        $pct       = $budget['initial_minutes'] > 0
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
        $status_label = match ($status) {
            'danger'  => __tt('Over budget'),
            'warning' => __tt('Threshold reached'),
            default   => __('OK'),
        };

        echo "<div class='p-3'>";

        // Bloc synthèse
        echo "<table class='tab_cadre_fixe mb-3'>";
        echo "<tr><th colspan='4'>" . __tt('Contract time tracking') . '</th></tr>';
        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'><strong>" . __tt('Initial time') . "</strong><br>"
            . htmlescape(self::formatMinutes((int) $budget['initial_minutes'])) . '</td>';
        echo "<td class='p-3'><strong>" . __tt('Consumed time') . "</strong><br>"
            . htmlescape(self::formatMinutes($spent)) . '</td>';
        echo "<td class='p-3'><strong>" . __tt('Remaining time') . "</strong><br>"
            . "<span class='" . self::getStatusCssClass($status) . "'>"
            . htmlescape(self::formatMinutes($remaining)) . '</span></td>';
        echo "<td class='p-3'><strong>" . __tt('Alert threshold') . "</strong><br>"
            . htmlescape(self::formatMinutes((int) $budget['alert_threshold_minutes']))
            . "<br><span class='badge {$badge_class} mt-1'>" . htmlescape($status_label) . '</span></td>';
        echo '</tr>';
        echo "<tr class='tab_bg_1'><td colspan='4' class='px-3 pb-3'>";
        echo "<div class='progress' style='height:20px'>";
        echo "<div class='progress-bar {$bar_class}' role='progressbar' style='width:{$pct}%'"
            . " aria-valuenow='{$pct}' aria-valuemin='0' aria-valuemax='100'>{$pct}%</div>";
        echo "</div>";
        echo '</td></tr>';
        echo '</table>';

        // Formulaire
        if (Contract::canUpdate()) {
            $initial_value = self::getDisplayDurationValue((int) $budget['initial_minutes']);
            $initial_unit  = self::getDisplayDurationUnit((int) $budget['initial_minutes']);
            $alert_value   = self::getDisplayDurationValue((int) $budget['alert_threshold_minutes']);
            $alert_unit    = self::getDisplayDurationUnit((int) $budget['alert_threshold_minutes']);

            echo "<form method='post' action='"
                . htmlescape(self::getPluginWebDir() . '/front/contractbudget.form.php') . "'>";
            echo Html::hidden('contracts_id', ['value' => $contracts_id]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __tt('Budget settings') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Initial time') . "</label><br>";
            echo "<div class='d-flex gap-2 align-items-center'>";
            echo "<input type='number' min='0' step='0.25' name='initial_value'"
                . " class='form-control' style='width:100px' value='" . htmlescape((string) $initial_value) . "'>";
            Dropdown::showFromArray('initial_unit', [
                'minutes' => __tt('Minutes'),
                'hours'   => __tt('Hours'),
            ], ['value' => $initial_unit]);
            echo "</div></td>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __tt('Alert threshold') . "</label><br>";
            echo "<div class='d-flex gap-2 align-items-center'>";
            echo "<input type='number' min='0' step='0.25' name='alert_value'"
                . " class='form-control' style='width:100px' value='" . htmlescape((string) $alert_value) . "'>";
            Dropdown::showFromArray('alert_unit', [
                'minutes' => __tt('Minutes'),
                'hours'   => __tt('Hours'),
            ], ['value' => $alert_unit]);
            echo "</div></td>";
            echo '</tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Active') . "</label><br>";
            echo "<input type='checkbox' class='form-check-input' name='is_active' value='1'"
                . ((int) $budget['is_active'] === 1 ? " checked='checked'" : '') . '>';
            echo "</td>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>" . __('Comments') . "</label><br>";
            echo "<textarea name='comment' class='form-control'>"
                . htmlescape((string) $budget['comment']) . '</textarea>';
            echo "</td>";
            echo '</tr>';
            echo "<tr><td colspan='4' class='p-3 text-end'>";
            echo "<button type='submit' name='update' class='btn btn-primary'>";
            echo "<i class='ti ti-device-floppy me-1'></i>" . htmlescape(_x('button', 'Save'));
            echo "</button>";
            echo '</td></tr>';
            echo '</table>';
            Html::closeForm();
        }

        echo '</div>';
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

    private static function getDisplayDurationUnit(int $minutes): string
    {
        if ($minutes > 0 && $minutes % 60 === 0) {
            return 'hours';
        }

        return 'minutes';
    }

    private static function getDisplayDurationValue(int $minutes): string
    {
        if (self::getDisplayDurationUnit($minutes) === 'hours') {
            return (string) ($minutes / 60);
        }

        return (string) $minutes;
    }
}
