<?php

use Symfony\Component\Mime\Address;

class PluginTimetrackerAlertConfig extends CommonDBTM
{
    public $dohistory = false;
    public static $rightname = 'contract';

    public static function getTypeName($nb = 0)
    {
        return __tt('Alert tracker');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_timetracker_alerts';
    }

    public static function getPluginWebDir(): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/timetracker';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === Contract::getType() && Contract::canView()) {
            return __tt('Alert tracker');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Contract::getType()) {
            self::showContractAlertTab((int) $item->getID());
        }
        return true;
    }

    public static function getForContract(int $contracts_id): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['contracts_id' => $contracts_id],
            'ORDER' => 'id ASC',
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function showContractAlertTab(int $contracts_id): void
    {
        $alerts = self::getForContract($contracts_id);
        $form_action = htmlescape(self::getPluginWebDir() . '/front/alertconfig.form.php');

        echo "<div class='p-3'>";

        // Alert list table
        echo "<table class='tab_cadre_fixe mb-3'>";
        echo "<tr><th>" . __tt('Type') . "</th>"
            . "<th>" . __tt('Days before') . "</th>"
            . "<th>" . __tt('Recipient email') . "</th>"
            . "<th>" . __('Active') . "</th>"
            . "<th>" . __('Actions') . "</th></tr>";

        if (empty($alerts)) {
            echo "<tr class='tab_bg_1'><td colspan='5' class='p-2 text-center'>"
                . __tt('No alerts configured.') . "</td></tr>";
        }

        foreach ($alerts as $alert) {
            $type_label = $alert['type'] === 'deadline' ? __tt('Deadline') : __tt('Threshold');
            $days = $alert['type'] === 'deadline' ? htmlescape((string) $alert['days_before']) : '—';
            $active_icon = (int) $alert['is_active'] === 1
                ? "<i class='ti ti-check text-success'></i>"
                : "<i class='ti ti-x text-danger'></i>";

            echo "<tr class='tab_bg_1'>";
            echo "<td class='p-2'>" . htmlescape($type_label) . "</td>";
            echo "<td class='p-2'>" . $days . "</td>";
            echo "<td class='p-2'>" . htmlescape($alert['recipient_email']) . "</td>";
            echo "<td class='p-2 text-center'>" . $active_icon . "</td>";
            echo "<td class='p-2'>";
            if (Contract::canUpdate()) {
                echo "<form method='post' action='{$form_action}' style='display:inline'>";
                echo Html::hidden('action', ['value' => 'delete']);
                echo Html::hidden('alerts_id', ['value' => (int) $alert['id']]);
                echo Html::hidden('contracts_id', ['value' => $contracts_id]);
                echo "<button type='submit' class='btn btn-sm btn-outline-danger'>"
                    . "<i class='ti ti-trash'></i></button>";
                Html::closeForm();
            }
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";

        // Add alert form
        if (Contract::canUpdate()) {
            echo "<form method='post' action='{$form_action}'>";
            echo Html::hidden('action', ['value' => 'add']);
            echo Html::hidden('contracts_id', ['value' => $contracts_id]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __tt('Add alert') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";

            echo "<td class='p-2'><label class='form-label fw-semibold'>" . __tt('Type') . "</label><br>";
            echo "<select name='type' class='form-select' style='width:auto' "
                . "onchange=\"document.getElementById('days_before_cell').style.display="
                . "this.value==='deadline'?'':'none'\">";
            echo "<option value='deadline'>" . htmlescape(__tt('Deadline')) . "</option>";
            echo "<option value='threshold'>" . htmlescape(__tt('Threshold')) . "</option>";
            echo "</select></td>";

            echo "<td class='p-2' id='days_before_cell'>"
                . "<label class='form-label fw-semibold'>" . __tt('Days before') . "</label><br>";
            echo "<input type='number' name='days_before' min='1' class='form-control' "
                . "style='width:100px' value='30'>";
            echo "</td>";

            echo "<td class='p-2'><label class='form-label fw-semibold'>" . __tt('Recipient email') . "</label><br>";
            echo "<input type='email' name='recipient_email' class='form-control' style='width:240px' value=''>";
            echo "</td>";

            echo "<td class='p-2'><label class='form-label fw-semibold'>" . __('Active') . "</label><br>";
            echo "<input type='checkbox' class='form-check-input' name='is_active' value='1' checked='checked'>";
            echo "</td>";

            echo "</tr>";
            echo "<tr><td colspan='4' class='p-2 text-end'>";
            echo "<button type='submit' class='btn btn-primary'>"
                . "<i class='ti ti-plus me-1'></i>" . htmlescape(__tt('Add alert')) . "</button>";
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
        }

        echo "</div>";
    }

    public static function cronSendAlerts(?CronTask $task): int
    {
        global $DB;

        $sent = 0;
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iterator as $alert) {
            $contracts_id = (int) $alert['contracts_id'];
            $contract = new Contract();
            if (!$contract->getFromDB($contracts_id)) {
                continue;
            }

            if ($alert['type'] === 'deadline') {
                $sent += self::processDeadlineAlert($alert, $contract);
            } elseif ($alert['type'] === 'threshold') {
                $sent += self::processThresholdAlert($alert, $contract);
            }
        }

        if ($task !== null) {
            $task->addVolume($sent);
        }

        return $sent > 0 ? 1 : 0;
    }

    private static function processDeadlineAlert(array $alert, Contract $contract): int
    {
        $begin_date = $contract->fields['begin_date'] ?? null;
        $duration   = (int) ($contract->fields['duration'] ?? 0);
        if (!$begin_date || $duration <= 0) {
            return 0;
        }

        $end_ts   = strtotime("+{$duration} months", strtotime($begin_date));
        $now_ts   = time();
        $diff_days = (int) ceil(($end_ts - $now_ts) / 86400);
        $days_before = (int) $alert['days_before'];

        if ($diff_days > $days_before || $diff_days < 0) {
            return 0;
        }

        // Avoid resending within 23 hours
        if (!empty($alert['last_sent_at'])) {
            $last_ts = strtotime($alert['last_sent_at']);
            if ($last_ts !== false && (time() - $last_ts) < 23 * 3600) {
                return 0;
            }
        }

        $contract_name = $contract->getName();
        $end_date_str  = date('Y-m-d', $end_ts);
        $subject = sprintf(
            __tt('[GLPI Timetracker] Contract "%s" — deadline in %d days'),
            $contract_name,
            $diff_days
        );
        $body = sprintf(
            __tt("Contract: %s\nEnd date: %s\nDays remaining: %d\n\nThis is an automated alert from GLPI Timetracker."),
            $contract_name,
            $end_date_str,
            $diff_days
        );

        if (!self::sendMail($alert['recipient_email'], $subject, $body)) {
            return 0;
        }

        self::updateLastSent((int) $alert['id']);
        return 1;
    }

    private static function processThresholdAlert(array $alert, Contract $contract): int
    {
        // One-shot: only fires once; reset last_sent_at manually to re-arm after budget reset
        if (!empty($alert['last_sent_at'])) {
            return 0;
        }

        $contracts_id = (int) $contract->getID();
        $budget = PluginTimetrackerContractBudget::getForContract($contracts_id);
        if ($budget === null || (int) $budget['alert_threshold_minutes'] <= 0) {
            return 0;
        }

        $spent   = PluginTimetrackerContractBudget::getSpentMinutes($contracts_id);
        $threshold = (int) $budget['alert_threshold_minutes'];
        if ($spent < $threshold) {
            return 0;
        }

        $contract_name = $contract->getName();
        $initial       = (int) $budget['initial_minutes'];
        $remaining     = $initial - $spent;
        $subject = sprintf(
            __tt('[GLPI Timetracker] Budget threshold reached — contract "%s"'),
            $contract_name
        );
        $body = sprintf(
            __tt("Contract: %s\nInitial budget: %s\nConsumed: %s\nThreshold: %s\nRemaining: %s\n\nThis is an automated alert from GLPI Timetracker."),
            $contract_name,
            PluginTimetrackerContractBudget::formatMinutes($initial),
            PluginTimetrackerContractBudget::formatMinutes($spent),
            PluginTimetrackerContractBudget::formatMinutes($threshold),
            PluginTimetrackerContractBudget::formatMinutes($remaining)
        );

        if (!self::sendMail($alert['recipient_email'], $subject, $body)) {
            return 0;
        }

        self::updateLastSent((int) $alert['id']);
        return 1;
    }

    private static function sendMail(string $to, string $subject, string $body): bool
    {
        try {
            $mailer = new GLPIMailer();
            $mailer->getEmail()
                ->to(new Address($to))
                ->subject($subject)
                ->text($body);
            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            Toolbox::logError('PluginTimetrackerAlertConfig::sendMail failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function updateLastSent(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['last_sent_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }
}
