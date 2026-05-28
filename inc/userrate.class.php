<?php

class PluginTimetrackerUserRate extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'user';

    public const DEFAULT_TECH_HOURLY_RATE_CENTS = 5000;

    public static function getTypeName($nb = 0)
    {
        return _n('Technician hourly rate', 'Technician hourly rates', $nb, 'timetracker');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_timetracker_userrates';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === User::getType() && User::canView()) {
            return "<span class='d-flex align-items-center gap-1'><i class='ti ti-currency-euro'></i>" . __tt('Hourly rate') . "</span>";
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === User::getType()) {
            self::showUserTab((int) $item->getID());
        }

        return true;
    }

    public static function getGlobalRateCents(): int
    {
        $conf = Config::getConfigurationValues('plugin:timetracker');
        if (isset($conf['tech_hourly_rate_cents']) && is_numeric($conf['tech_hourly_rate_cents'])) {
            return max(0, (int) $conf['tech_hourly_rate_cents']);
        }

        return self::DEFAULT_TECH_HOURLY_RATE_CENTS;
    }

    public static function getRateCents(?int $users_id): int
    {
        if ($users_id !== null && $users_id > 0) {
            global $DB;

            $iterator = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['users_id' => $users_id],
                'LIMIT' => 1,
            ]);

            foreach ($iterator as $row) {
                if (isset($row['hourly_rate_cents']) && $row['hourly_rate_cents'] !== null) {
                    return max(0, (int) $row['hourly_rate_cents']);
                }
            }
        }

        return self::getGlobalRateCents();
    }

    public static function getForUser(int $users_id): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    public static function upsertForUser(int $users_id, array $input): bool
    {
        $entry    = new self();
        $existing = self::getForUser($users_id);
        $raw      = trim((string) ($input['hourly_rate_cents'] ?? ''));
        $cents    = ($raw === '' || !is_numeric($raw)) ? null : max(0, (int) $raw);

        if ($existing !== null) {
            if ($cents === null) {
                return (bool) $entry->delete(['id' => (int) $existing['id']], true);
            }
            return (bool) $entry->update([
                'id'                => (int) $existing['id'],
                'users_id'          => $users_id,
                'hourly_rate_cents' => $cents,
            ]);
        }

        if ($cents === null) {
            return true;
        }

        return (bool) $entry->add([
            'users_id'          => $users_id,
            'hourly_rate_cents' => $cents,
        ]);
    }

    public static function formatRate(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    private static function showUserTab(int $users_id): void
    {
        $existing = self::getForUser($users_id);
        $current  = $existing['hourly_rate_cents'] ?? null;
        $global   = self::getGlobalRateCents();
        $effective = self::getRateCents($users_id);

        $form_action = htmlescape(PluginTimetrackerContractBudget::getPluginWebDir() . '/front/userrate.form.php');

        echo "<div class='p-3'>";

        echo "<table class='tab_cadre_fixe mb-3'>";
        echo "<tr><th colspan='2'>" . __tt('Hourly rate') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td class='p-3'>" . __tt('Global rate (plugin config)')
            . "</td><td class='p-3'><strong>" . htmlescape(self::formatRate($global)) . "</strong></td></tr>";
        echo "<tr class='tab_bg_1'><td class='p-3'>" . __tt('Effective rate for this user')
            . "</td><td class='p-3'><strong>" . htmlescape(self::formatRate($effective)) . "</strong>"
            . ($current !== null ? " <span class='badge bg-info ms-2'>" . __tt('Override') . "</span>" : '')
            . "</td></tr>";
        echo "</table>";

        if (User::canUpdate()) {
            echo "<form method='post' action='{$form_action}'>";
            echo Html::hidden('users_id', ['value' => $users_id]);
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='2'>" . __tt('Override hourly rate') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-3'><label class='form-label fw-semibold'>"
                . __tt('Hourly rate override (cents)') . "</label></td>";
            echo "<td class='p-3'>";
            echo "<input type='number' min='0' name='hourly_rate_cents' class='form-control' style='width:160px' value='"
                . htmlescape((string) ($current ?? '')) . "' placeholder='"
                . htmlescape((string) $global) . "'>";
            echo "<small class='text-muted d-block mt-1'>"
                . __tt('Leave empty to use the global rate.') . "</small>";
            echo "</td></tr>";
            echo "<tr><td colspan='2' class='p-3 text-end'>";
            echo "<button type='submit' name='save' class='btn btn-primary'>"
                . "<i class='ti ti-device-floppy me-1'></i>" . htmlescape(_x('button', 'Save')) . "</button>";
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
        }

        echo "</div>";
    }
}
