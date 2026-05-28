# Alert Tracker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a configurable email alert system to GLPI contracts — alerts fire X days before contract end date or when the budget threshold is reached, sent via GLPI's built-in SMTP.

**Architecture:** New table `glpi_plugin_timetracker_alerts` stores per-contract alert rules. A new `PluginTimetrackerAlertConfig` class handles the "Alert tracker" tab on Contract items and a daily cron method (`cronSendAlerts`) that scans active alerts and sends emails via `GLPIMailer`. No `NotificationTarget` — direct send only.

**Tech Stack:** PHP 8.2, GLPI 11 (`CommonDBTM`, `CronTask`, `GLPIMailer`, `Symfony\Component\Mime\Address`), Bootstrap 5 + Tabler Icons (both globally available in GLPI 11), gettext .po/.mo i18n

---

## File Map

| File | Action |
|---|---|
| `inc/alertconfig.class.php` | Create — full class: tab, CRUD, cron |
| `front/alertconfig.form.php` | Create — add/delete POST handler |
| `hook.php` | Modify — require new class, create/drop table, register cron |
| `setup.php` | Modify — register class with addtabon, bump version to 0.1.5 |
| `plugin.xml` | Modify — bump version to 0.1.5 |
| `locales/timetracker.pot` | Modify — add 10 new msgid entries |
| `locales/fr_FR.po` | Modify — add 10 French translations |
| `locales/es_ES.po` | Modify — add 10 Spanish translations |
| `locales/fr_FR.mo` | Regenerate |
| `locales/es_ES.mo` | Regenerate |

---

## Task 1: DB table + hook.php

**Files:**
- Modify: `hook.php`

- [ ] **Step 1: Add require and table creation to hook.php**

Replace the two `require_once` lines at the top of `hook.php` with:

```php
require_once __DIR__ . '/inc/contractbudget.class.php';
require_once __DIR__ . '/inc/timeentry.class.php';
require_once __DIR__ . '/inc/alertconfig.class.php';
```

Then inside `plugin_timetracker_install()`, after `$migration->executeMigration();` but before the `Config::setConfigurationValues` line, add:

```php
    $alert_table = PluginTimetrackerAlertConfig::getTable();
    if (!$DB->tableExists($alert_table)) {
        $DB->doQuery(
            "CREATE TABLE `$alert_table` (
               `id` int unsigned NOT NULL AUTO_INCREMENT,
               `contracts_id` int unsigned NOT NULL DEFAULT '0',
               `type` varchar(20) NOT NULL DEFAULT 'deadline',
               `days_before` int unsigned NULL DEFAULT NULL,
               `recipient_email` varchar(255) NOT NULL DEFAULT '',
               `is_active` tinyint NOT NULL DEFAULT '1',
               `last_sent_at` datetime NULL DEFAULT NULL,
               `date_creation` timestamp NULL DEFAULT NULL,
               `date_mod` timestamp NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `contracts_id` (`contracts_id`),
               KEY `type` (`type`),
               KEY `is_active` (`is_active`),
               KEY `date_mod` (`date_mod`),
               KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    } else {
        $migration->addField($alert_table, 'contracts_id', 'integer', ['value' => 0]);
        $migration->addField($alert_table, 'type', 'string', ['value' => 'deadline']);
        $migration->addField($alert_table, 'days_before', 'integer', ['null' => true]);
        $migration->addField($alert_table, 'recipient_email', 'string', ['value' => '']);
        $migration->addField($alert_table, 'is_active', 'bool', ['value' => 1]);
        $migration->addField($alert_table, 'last_sent_at', 'datetime', ['null' => true]);
        $migration->addField($alert_table, 'date_mod', 'timestamp', ['null' => true]);
        $migration->addField($alert_table, 'date_creation', 'timestamp', ['null' => true]);
        $migration->addKey($alert_table, 'contracts_id');
        $migration->addKey($alert_table, 'type');
        $migration->addKey($alert_table, 'is_active');
    }
```

Also add `CronTask::register` call at the end of `plugin_timetracker_install()`, just before `return true;`:

```php
    CronTask::register(
        'PluginTimetrackerAlertConfig',
        'sendAlerts',
        DAY_TIMESTAMP,
        ['comment' => 'Send timetracker contract alerts', 'state' => CronTask::STATE_WAITING]
    );
```

In `plugin_timetracker_uninstall()`, add before `return true;`:

```php
    $alert_table = PluginTimetrackerAlertConfig::getTable();
    if ($DB->tableExists($alert_table)) {
        $DB->dropTable($alert_table, true);
    }

    CronTask::unregister('PluginTimetrackerAlertConfig');
```

- [ ] **Step 2: Verify hook.php syntax**

```bash
php -l hook.php
```

Expected: `No syntax errors detected in hook.php`

- [ ] **Step 3: Commit**

```bash
git add hook.php
git commit -m "feat: add alertconfig table and cron registration in hook.php"
```

---

## Task 2: inc/alertconfig.class.php

**Files:**
- Create: `inc/alertconfig.class.php`

- [ ] **Step 1: Create the full class file**

Create `inc/alertconfig.class.php` with the following content:

```php
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
            return false;
        }
    }

    private static function updateLastSent(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['last_sent_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l inc/alertconfig.class.php
```

Expected: `No syntax errors detected in inc/alertconfig.class.php`

- [ ] **Step 3: Commit**

```bash
git add inc/alertconfig.class.php
git commit -m "feat: add PluginTimetrackerAlertConfig class with tab and cron"
```

---

## Task 3: front/alertconfig.form.php

**Files:**
- Create: `front/alertconfig.form.php`

- [ ] **Step 1: Create the form handler**

Create `front/alertconfig.form.php`:

```php
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
```

- [ ] **Step 2: Verify syntax**

```bash
php -l front/alertconfig.form.php
```

Expected: `No syntax errors detected in front/alertconfig.form.php`

- [ ] **Step 3: Commit**

```bash
git add front/alertconfig.form.php
git commit -m "feat: add alertconfig form handler (add/delete actions)"
```

---

## Task 4: setup.php + plugin.xml version bump

**Files:**
- Modify: `setup.php`
- Modify: `plugin.xml`

- [ ] **Step 1: Register class and bump version in setup.php**

In `setup.php`, change:

```php
define('PLUGIN_TIMETRACKER_VERSION', '0.1.4');
```

to:

```php
define('PLUGIN_TIMETRACKER_VERSION', '0.1.5');
```

Inside `plugin_init_timetracker()`, after the existing `Plugin::registerClass` calls, add:

```php
    Plugin::registerClass(PluginTimetrackerAlertConfig::class, [
        'addtabon' => ['Contract'],
    ]);
```

- [ ] **Step 2: Bump version in plugin.xml**

In `plugin.xml`, change:

```xml
<version>0.1.4</version>
```

to:

```xml
<version>0.1.5</version>
```

- [ ] **Step 3: Verify syntax**

```bash
php -l setup.php
```

Expected: `No syntax errors detected in setup.php`

- [ ] **Step 4: Commit**

```bash
git add setup.php plugin.xml
git commit -m "feat: register PluginTimetrackerAlertConfig tab, bump version to 0.1.5"
```

---

## Task 5: i18n — add new strings

**Files:**
- Modify: `locales/timetracker.pot`
- Modify: `locales/fr_FR.po`
- Modify: `locales/es_ES.po`
- Regenerate: `locales/fr_FR.mo`, `locales/es_ES.mo`

- [ ] **Step 1: Add new msgid entries to timetracker.pot**

Append to `locales/timetracker.pot` (after the last `msgstr ""`):

```
msgid "Alert tracker"
msgstr ""

msgid "Deadline"
msgstr ""

msgid "Threshold"
msgstr ""

msgid "Days before"
msgstr ""

msgid "Recipient email"
msgstr ""

msgid "Alert added."
msgstr ""

msgid "Alert deleted."
msgstr ""

msgid "Invalid email address."
msgstr ""

msgid "No alerts configured."
msgstr ""

msgid "Add alert"
msgstr ""

msgid "[GLPI Timetracker] Contract \"%s\" — deadline in %d days"
msgstr ""

msgid "Contract: %s\nEnd date: %s\nDays remaining: %d\n\nThis is an automated alert from GLPI Timetracker."
msgstr ""

msgid "[GLPI Timetracker] Budget threshold reached — contract \"%s\""
msgstr ""

msgid "Contract: %s\nInitial budget: %s\nConsumed: %s\nThreshold: %s\nRemaining: %s\n\nThis is an automated alert from GLPI Timetracker."
msgstr ""
```

- [ ] **Step 2: Add French translations to fr_FR.po**

Append to `locales/fr_FR.po` (after the last entry):

```
msgid "Alert tracker"
msgstr "Alertes"

msgid "Deadline"
msgstr "Échéance"

msgid "Threshold"
msgstr "Seuil budget"

msgid "Days before"
msgstr "Jours avant"

msgid "Recipient email"
msgstr "Email destinataire"

msgid "Alert added."
msgstr "Alerte ajoutée."

msgid "Alert deleted."
msgstr "Alerte supprimée."

msgid "Invalid email address."
msgstr "Adresse email invalide."

msgid "No alerts configured."
msgstr "Aucune alerte configurée."

msgid "Add alert"
msgstr "Ajouter une alerte"

msgid "[GLPI Timetracker] Contract \"%s\" — deadline in %d days"
msgstr "[GLPI Timetracker] Contrat \"%s\" — échéance dans %d jours"

msgid "Contract: %s\nEnd date: %s\nDays remaining: %d\n\nThis is an automated alert from GLPI Timetracker."
msgstr "Contrat : %s\nDate de fin : %s\nJours restants : %d\n\nCeci est une alerte automatique de GLPI Timetracker."

msgid "[GLPI Timetracker] Budget threshold reached — contract \"%s\""
msgstr "[GLPI Timetracker] Seuil budget atteint — contrat \"%s\""

msgid "Contract: %s\nInitial budget: %s\nConsumed: %s\nThreshold: %s\nRemaining: %s\n\nThis is an automated alert from GLPI Timetracker."
msgstr "Contrat : %s\nBudget initial : %s\nConsommé : %s\nSeuil : %s\nRestant : %s\n\nCeci est une alerte automatique de GLPI Timetracker."
```

- [ ] **Step 3: Add Spanish translations to es_ES.po**

Append to `locales/es_ES.po` (after the last entry):

```
msgid "Alert tracker"
msgstr "Rastreador de alertas"

msgid "Deadline"
msgstr "Vencimiento"

msgid "Threshold"
msgstr "Umbral presupuesto"

msgid "Days before"
msgstr "Días antes"

msgid "Recipient email"
msgstr "Email destinatario"

msgid "Alert added."
msgstr "Alerta añadida."

msgid "Alert deleted."
msgstr "Alerta eliminada."

msgid "Invalid email address."
msgstr "Dirección de correo no válida."

msgid "No alerts configured."
msgstr "No hay alertas configuradas."

msgid "Add alert"
msgstr "Añadir alerta"

msgid "[GLPI Timetracker] Contract \"%s\" — deadline in %d days"
msgstr "[GLPI Timetracker] Contrato \"%s\" — vencimiento en %d días"

msgid "Contract: %s\nEnd date: %s\nDays remaining: %d\n\nThis is an automated alert from GLPI Timetracker."
msgstr "Contrato: %s\nFecha de fin: %s\nDías restantes: %d\n\nEsta es una alerta automática de GLPI Timetracker."

msgid "[GLPI Timetracker] Budget threshold reached — contract \"%s\""
msgstr "[GLPI Timetracker] Umbral de presupuesto alcanzado — contrato \"%s\""

msgid "Contract: %s\nInitial budget: %s\nConsumed: %s\nThreshold: %s\nRemaining: %s\n\nThis is an automated alert from GLPI Timetracker."
msgstr "Contrato: %s\nPresupuesto inicial: %s\nConsumido: %s\nUmbral: %s\nRestante: %s\n\nEsta es una alerta automática de GLPI Timetracker."
```

- [ ] **Step 4: Recompile .mo files**

```bash
python3 tools/compile_mo.py
```

Expected output:
```
Compiled es_ES.po -> es_ES.mo (N strings)
Compiled fr_FR.po -> fr_FR.mo (N strings)
```

- [ ] **Step 5: Commit**

```bash
git add locales/
git commit -m "feat: add alert tracker i18n strings (EN/FR/ES)"
```

---

## Task 6: Deploy and verify

**Files:** None (deployment + manual testing)

- [ ] **Step 1: Check the full plugin installs without errors**

On the Docker dev environment, reinstall the plugin to trigger `plugin_timetracker_install()`:

Go to GLPI > Setup > Plugins > Timetracker > Uninstall, then reinstall, then verify:

```sql
SHOW CREATE TABLE glpi_plugin_timetracker_alerts;
```

Expected: table exists with columns `id`, `contracts_id`, `type`, `days_before`, `recipient_email`, `is_active`, `last_sent_at`, `date_creation`, `date_mod`.

- [ ] **Step 2: Verify the "Alert tracker" tab appears on a contract**

Open any Contract in GLPI. The tabs should now show: `Time budget | Alert tracker`. Click "Alert tracker" — the empty table with "No alerts configured." message should appear, plus the "Add alert" form below.

- [ ] **Step 3: Add a deadline alert**

Fill in: Type = Deadline, Days before = 30, Email = test@example.com, Active = checked. Click "Add alert".

Expected: redirect back to contract, flash message "Alert added.", alert row appears in the table.

- [ ] **Step 4: Add a threshold alert**

Fill in: Type = Threshold, Email = test@example.com. Click "Add alert".

Expected: alert row appears, "Days before" column shows "—".

- [ ] **Step 5: Delete an alert**

Click the red trash button on any row. Expected: redirect, flash "Alert deleted.", row removed from table.

- [ ] **Step 6: Verify cron is registered**

Go to GLPI > Setup > Automatic actions. A task named `PluginTimetrackerAlertConfig::sendAlerts` should appear with mode Daily.

- [ ] **Step 7: Commit version tag**

```bash
git add -A
git commit -m "feat: alert tracker — deploy verified at 0.1.5" --allow-empty
git tag v0.1.5
```
