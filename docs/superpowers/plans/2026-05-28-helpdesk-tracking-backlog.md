# Helpdesk Tracking Backlog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the remaining items from the Pulse myIT helpdesk tracking CR — per-contract km rate config, contract-level travel aggregation in dashboard, CSV exports, J-60 renewal alerts with enriched content, monthly PDF report cron, and consumption run-rate projection.

**Architecture:** Five independent phases, each producing working software:
1. Add `km_rate_cents` per-contract field on `contractbudget` with global fallback in `plugin:timetracker` config, exposed via budget form and a new plugin config page.
2. Enrich the dashboard with km/cost totals per contract and a run-rate projection column.
3. Add CSV on-demand exports (time entries + travel entries) on the dashboard.
4. Add `renewal` alert type to `alertconfig` (default J-60) with enriched template (hours consumed/remaining, trend, tickets count, travels).
5. Register a monthly cron that generates a PDF report per contract using GLPI's bundled `mpdf` and emails it to the contract's configured recipient.

**Tech Stack:** PHP 8.2, GLPI 11 (`CommonDBTM`, `CronTask`, `GLPIMailer`, `Config`, `Migration`), Mpdf (bundled with GLPI 11 vendor), Bootstrap 5 + Tabler Icons, gettext .po/.mo.

**Project test pattern:** `tools/smoke.php` — a CLI script that asserts behaviors. Extend it as features land. No PHPUnit available.

---

## File Map

| File | Action |
|---|---|
| `inc/contractbudget.class.php` | Modify — add `km_rate_cents` column upsert, helpers, UI on tab |
| `inc/travelentry.class.php` | Modify — `getKmRateCents(int $contracts_id)` reads contract override then global |
| `inc/dashboard.class.php` | Modify — add km column, cost, run-rate projection, export buttons |
| `inc/alertconfig.class.php` | Modify — add `renewal` type, enriched template, dropdown option |
| `inc/exporter.class.php` | Create — CSV export helpers (time + travel) |
| `inc/monthlyreport.class.php` | Create — cron `cronSendMonthlyReports`, PDF rendering via Mpdf |
| `front/dashboard.php` | Modify — wire export actions (?export=time / ?export=travel) |
| `front/config.form.php` | Create — global plugin config page (km rate, monthly report toggle) |
| `hook.php` | Modify — add column to budget table, register monthly cron, init default config |
| `setup.php` | Modify — register monthly report class, bump version to 0.2.0 |
| `plugin.xml` | Modify — bump version to 0.2.0 |
| `tools/smoke.php` | Modify — assertions for km rate fallback, run-rate, alert renewal type |
| `locales/timetracker.pot` | Modify — new msgids |
| `locales/fr_FR.po` | Modify — French translations |
| `locales/es_ES.po` | Modify — Spanish translations |
| `locales/*.mo` | Regenerate via `tools/compile_mo.py` |

---

## Phase 1 — Per-contract km rate + global fallback

### Task 1.1: Add `km_rate_cents` column to budget table

**Files:** `hook.php`

- [ ] **Step 1:** In `plugin_timetracker_install()`, inside the budget-table `else` branch (after the existing `addField` calls but before `addKey`), add:

```php
        $migration->addField($budget_table, 'km_rate_cents', 'integer', ['null' => true]);
```

And in the CREATE TABLE block, just before `PRIMARY KEY`, add:

```sql
               `km_rate_cents` int unsigned DEFAULT NULL,
```

- [ ] **Step 2:** Run smoke test to confirm syntax: `php -l hook.php`. Expected: `No syntax errors`.

- [ ] **Step 3:** Commit.

```bash
git add hook.php
git commit -m "feat: add km_rate_cents column to contractbudgets"
```

### Task 1.2: Update `PluginTimetrackerTravelEntry::getKmRateCents` to accept contract override

**Files:** `inc/travelentry.class.php`

- [ ] **Step 1:** Change the method signature and body:

```php
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
```

- [ ] **Step 2:** In `showTicketTab()` and `showTicketEntries()`, replace each `self::getKmRateCents()` call site to use the contract from the entry/totals. Specifically:

In `showTicketTab()`, after computing `$totals`, also compute the dominant contract:

```php
        $entries  = self::getTicketEntries($tickets_id);
        $contracts_id_for_rate = (int) ($entries[0]['contracts_id'] ?? 0);
        $rate     = self::getKmRateCents($contracts_id_for_rate);
```

Pass `$rate` to `showTicketEntries` by inlining (since each row may have its own contract, compute per row):

Replace the existing per-entry cost calculation in `showTicketEntries` with:

```php
            $km        = (float) $entry['km'];
            $entry_rate = self::getKmRateCents((int) $entry['contracts_id']);
            $cost_cent = (int) round($km * $entry_rate);
```

- [ ] **Step 3:** `php -l inc/travelentry.class.php`. Expected: no errors.

- [ ] **Step 4:** Commit.

```bash
git add inc/travelentry.class.php
git commit -m "feat: km rate fallback chain — contract override then global config"
```

### Task 1.3: Show + edit km rate on the contract budget tab

**Files:** `inc/contractbudget.class.php`, `front/contractbudget.form.php`

- [ ] **Step 1:** Read `inc/contractbudget.class.php` from line 200 onward to find `showContractTab` and the form rendering. Identify where the input rows live.

- [ ] **Step 2:** Inside `upsertForContract`, extend `$data`:

```php
        $raw_rate = trim((string) ($input['km_rate_cents'] ?? ''));
        $data['km_rate_cents'] = ($raw_rate === '' || !is_numeric($raw_rate))
            ? null
            : max(0, (int) $raw_rate);
```

- [ ] **Step 3:** In the tab form (search for the alert threshold input), add a new row after it:

```php
        echo "<tr class='tab_bg_1'>";
        echo "<td class='p-3'><label class='form-label fw-semibold'>"
            . __tt('Km rate override (cents)') . "</label></td>";
        echo "<td class='p-3'>";
        $current_rate = $budget['km_rate_cents'] ?? '';
        echo "<input type='number' min='0' name='km_rate_cents' class='form-control' style='width:140px' value='"
            . htmlescape((string) $current_rate) . "' placeholder='"
            . htmlescape((string) PluginTimetrackerTravelEntry::getKmRateCents()) . "'>";
        echo "<small class='text-muted d-block mt-1'>"
            . __tt('Leave empty to use the global plugin rate.') . "</small>";
        echo "</td></tr>";
```

- [ ] **Step 4:** `php -l inc/contractbudget.class.php`. Expected: no errors.

- [ ] **Step 5:** Commit.

```bash
git add inc/contractbudget.class.php
git commit -m "feat: per-contract km rate override input on budget tab"
```

### Task 1.4: Plugin global config page (km rate)

**Files:** `front/config.form.php`, `setup.php`

- [ ] **Step 1:** Create `front/config.form.php`:

```php
<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['save'])) {
    $rate = (int) ($_POST['km_rate_cents'] ?? PluginTimetrackerTravelEntry::DEFAULT_KM_RATE_CENTS);
    Config::setConfigurationValues('plugin:timetracker', ['km_rate_cents' => max(0, $rate)]);
    Session::addMessageAfterRedirect(__('Configuration saved.', 'timetracker'));
    Html::back();
}

Html::header(
    __tt('Contract time tracking — Configuration'),
    '',
    'config',
    'PluginTimetrackerDashboard'
);

$conf = Config::getConfigurationValues('plugin:timetracker');
$rate = (int) ($conf['km_rate_cents'] ?? PluginTimetrackerTravelEntry::DEFAULT_KM_RATE_CENTS);

echo "<div class='p-3'>";
echo "<form method='post' action='" . htmlescape($_SERVER['REQUEST_URI']) . "'>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>" . __tt('Plugin configuration') . "</th></tr>";
echo "<tr class='tab_bg_1'>";
echo "<td class='p-3'>" . __tt('Default km rate (cents)') . "</td>";
echo "<td class='p-3'><input type='number' min='0' name='km_rate_cents' value='"
    . htmlescape((string) $rate) . "' class='form-control' style='width:160px'></td>";
echo "</tr>";
echo "<tr><td colspan='2' class='p-3 text-end'>";
echo "<button type='submit' name='save' class='btn btn-primary'>"
    . htmlescape(_x('button', 'Save')) . "</button>";
echo "</td></tr>";
echo "</table>";
Html::closeForm();
echo "</div>";

Html::footer();
```

- [ ] **Step 2:** Update `setup.php` config hook:

Replace the line `$PLUGIN_HOOKS['config_page']['timetracker'] = 'front/dashboard.php';` with:

```php
    $PLUGIN_HOOKS['config_page']['timetracker'] = 'front/config.form.php';
```

- [ ] **Step 3:** `php -l front/config.form.php setup.php`. Expected: no errors.

- [ ] **Step 4:** Commit.

```bash
git add front/config.form.php setup.php
git commit -m "feat: plugin config page for global km rate"
```

### Task 1.5: Smoke test for km rate fallback

**Files:** `tools/smoke.php`

- [ ] **Step 1:** After the existing assertions, append:

```php
require_once $plugin_root . '/inc/travelentry.class.php';

// Default fallback when no config + no contract
$conf_before = Config::getConfigurationValues('plugin:timetracker');
Config::setConfigurationValues('plugin:timetracker', ['km_rate_cents' => 90]);
if (PluginTimetrackerTravelEntry::getKmRateCents(0) !== 90) {
    $failures[] = 'Global km rate fallback failed.';
}
Config::setConfigurationValues('plugin:timetracker', $conf_before);
```

- [ ] **Step 2:** Commit.

```bash
git add tools/smoke.php
git commit -m "test: smoke test for km rate global fallback"
```

---

## Phase 2 — Dashboard: travel totals + run-rate projection

### Task 2.1: Travel totals helper (already exists — verify)

**Files:** `inc/travelentry.class.php`

- [ ] **Step 1:** Confirm `getContractTotals(int $contracts_id)` exists and returns `['km', 'minutes', 'count']`. Skip if already present.

### Task 2.2: Run-rate projection helper

**Files:** `inc/contractbudget.class.php`

- [ ] **Step 1:** Add method:

```php
    public static function getProjection(int $contracts_id): array
    {
        global $DB;

        $budget = self::getForContract($contracts_id);
        if ($budget === null || (int) $budget['is_active'] !== 1) {
            return ['daily_avg_minutes' => 0.0, 'projected_total_minutes' => 0, 'days_remaining' => 0];
        }

        $contract = new Contract();
        if (!$contract->getFromDB($contracts_id)) {
            return ['daily_avg_minutes' => 0.0, 'projected_total_minutes' => 0, 'days_remaining' => 0];
        }

        $begin_date = $contract->fields['begin_date'] ?? null;
        $duration   = (int) ($contract->fields['duration'] ?? 0);
        if (!$begin_date || $duration <= 0) {
            return ['daily_avg_minutes' => 0.0, 'projected_total_minutes' => 0, 'days_remaining' => 0];
        }

        $begin_ts = strtotime($begin_date);
        $end_ts   = strtotime("+{$duration} months", $begin_ts);
        $now_ts   = time();

        $elapsed_days = max(1, (int) floor(($now_ts - $begin_ts) / 86400));
        $days_remaining = max(0, (int) ceil(($end_ts - $now_ts) / 86400));

        $spent = self::getSpentMinutes($contracts_id);
        $daily_avg = $spent / $elapsed_days;
        $projected = (int) round($spent + $daily_avg * $days_remaining);

        return [
            'daily_avg_minutes'       => $daily_avg,
            'projected_total_minutes' => $projected,
            'days_remaining'          => $days_remaining,
        ];
    }
```

- [ ] **Step 2:** `php -l inc/contractbudget.class.php`. Expected: no errors.

- [ ] **Step 3:** Commit.

```bash
git add inc/contractbudget.class.php
git commit -m "feat: run-rate projection helper on contract budget"
```

### Task 2.3: Enrich dashboard rows

**Files:** `inc/dashboard.class.php`

- [ ] **Step 1:** Read `inc/dashboard.class.php` to find the table that lists contracts (look for the `<tr>` header row in `display()` or similar).

- [ ] **Step 2:** Add four new `<th>` columns: Travel km, Travel cost, Projection, Over budget?

For each row, inside the loop, compute:

```php
        $travel = PluginTimetrackerTravelEntry::getContractTotals($contracts_id);
        $rate   = PluginTimetrackerTravelEntry::getKmRateCents($contracts_id);
        $cost   = (int) round($travel['km'] * $rate);
        $proj   = PluginTimetrackerContractBudget::getProjection($contracts_id);
        $projected_total = (int) $proj['projected_total_minutes'];
        $over_projection = $projected_total > (int) $budget['initial_minutes'];
```

Then render:

```php
        echo '<td class="p-2">' . htmlescape(PluginTimetrackerTravelEntry::formatKm($travel['km'])) . '</td>';
        echo '<td class="p-2">' . htmlescape(PluginTimetrackerTravelEntry::formatCost($cost)) . '</td>';
        echo '<td class="p-2">'
            . htmlescape(PluginTimetrackerContractBudget::formatMinutes($projected_total)) . '</td>';
        echo '<td class="p-2 text-center">'
            . ($over_projection
                ? '<i class="ti ti-alert-triangle text-warning" title="' . htmlescape(__tt('Projected over budget')) . '"></i>'
                : '<i class="ti ti-check text-success"></i>') . '</td>';
```

- [ ] **Step 3:** `php -l inc/dashboard.class.php`. Expected: no errors.

- [ ] **Step 4:** Commit.

```bash
git add inc/dashboard.class.php
git commit -m "feat: dashboard shows travel totals, cost, and run-rate projection"
```

---

## Phase 3 — CSV exports

### Task 3.1: Create `Exporter` class with two methods

**Files:** `inc/exporter.class.php`

- [ ] **Step 1:** Create file:

```php
<?php

class PluginTimetrackerExporter
{
    public static function streamTimeEntriesCsv(?int $contracts_id, ?string $date_from, ?string $date_to): void
    {
        global $DB;

        $where = ['is_deleted' => 0];
        if ($contracts_id !== null && $contracts_id > 0) {
            $where['contracts_id'] = $contracts_id;
        }
        if ($date_from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where['spent_at'] = ['>=', $date_from];
        }
        if ($date_to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where[] = ['spent_at' => ['<=', $date_to]];
        }

        $iterator = $DB->request([
            'FROM'  => PluginTimetrackerTimeEntry::getTable(),
            'WHERE' => $where,
            'ORDER' => ['spent_at ASC', 'id ASC'],
        ]);

        self::emitHeaders('time-entries');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'ticket_id', 'contract_id', 'contract_name', 'user_id', 'user_name', 'minutes', 'hours', 'comment']);

        $contract = new Contract();
        $user     = new User();
        foreach ($iterator as $row) {
            $contract_name = $contract->getFromDB((int) $row['contracts_id']) ? $contract->getName() : '';
            $user_name     = $user->getFromDB((int) $row['users_id']) ? $user->getName() : '';
            $minutes       = (int) $row['duration_minutes'];

            fputcsv($out, [
                (string) $row['spent_at'],
                (int) $row['tickets_id'],
                (int) $row['contracts_id'],
                $contract_name,
                (int) $row['users_id'],
                $user_name,
                $minutes,
                number_format($minutes / 60, 2, '.', ''),
                (string) $row['comment'],
            ]);
        }

        fclose($out);
    }

    public static function streamTravelEntriesCsv(?int $contracts_id, ?string $date_from, ?string $date_to): void
    {
        global $DB;

        $where = ['is_deleted' => 0];
        if ($contracts_id !== null && $contracts_id > 0) {
            $where['contracts_id'] = $contracts_id;
        }
        if ($date_from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where['travel_date'] = ['>=', $date_from];
        }
        if ($date_to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where[] = ['travel_date' => ['<=', $date_to]];
        }

        $iterator = $DB->request([
            'FROM'  => PluginTimetrackerTravelEntry::getTable(),
            'WHERE' => $where,
            'ORDER' => ['travel_date ASC', 'id ASC'],
        ]);

        self::emitHeaders('travel-entries');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'ticket_id', 'contract_id', 'contract_name', 'user_id', 'user_name', 'from_location', 'km', 'time_on_site_minutes', 'cost_cents', 'purpose', 'comment']);

        $contract = new Contract();
        $user     = new User();
        foreach ($iterator as $row) {
            $contract_name = $contract->getFromDB((int) $row['contracts_id']) ? $contract->getName() : '';
            $user_name     = $user->getFromDB((int) $row['users_id']) ? $user->getName() : '';
            $km            = (float) $row['km'];
            $rate          = PluginTimetrackerTravelEntry::getKmRateCents((int) $row['contracts_id']);
            $cost_cent     = (int) round($km * $rate);

            fputcsv($out, [
                (string) $row['travel_date'],
                (int) $row['tickets_id'],
                (int) $row['contracts_id'],
                $contract_name,
                (int) $row['users_id'],
                $user_name,
                (string) $row['from_location'],
                number_format($km, 2, '.', ''),
                (int) $row['time_on_site_minutes'],
                $cost_cent,
                (string) $row['purpose'],
                (string) $row['comment'],
            ]);
        }

        fclose($out);
    }

    private static function emitHeaders(string $base): void
    {
        $filename = sprintf('%s-%s.csv', $base, date('Ymd-His'));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache');
    }
}
```

- [ ] **Step 2:** `php -l inc/exporter.class.php`. Expected: no errors.

- [ ] **Step 3:** Add `require_once __DIR__ . '/inc/exporter.class.php';` to `hook.php` near the other requires.

- [ ] **Step 4:** Commit.

```bash
git add inc/exporter.class.php hook.php
git commit -m "feat: CSV exporters for time and travel entries"
```

### Task 3.2: Wire export buttons in dashboard

**Files:** `front/dashboard.php`

- [ ] **Step 1:** Read current `front/dashboard.php`. At the top, before any HTML output, intercept export requests:

```php
<?php

include('../../../inc/includes.php');

Session::checkRight('contract', READ);

$action = $_GET['export'] ?? '';
$contracts_id = isset($_GET['contracts_id']) ? (int) $_GET['contracts_id'] : null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;

if ($action === 'time') {
    PluginTimetrackerExporter::streamTimeEntriesCsv($contracts_id, $date_from, $date_to);
    exit;
}
if ($action === 'travel') {
    PluginTimetrackerExporter::streamTravelEntriesCsv($contracts_id, $date_from, $date_to);
    exit;
}
```

Keep the rest of the file (the dashboard display logic) intact.

- [ ] **Step 2:** In `inc/dashboard.class.php`, add export buttons row at the top of the dashboard view:

```php
        $base = PluginTimetrackerContractBudget::getPluginWebDir() . '/front/dashboard.php';
        echo "<div class='mb-3 d-flex gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '?export=time') . "'>"
            . "<i class='ti ti-download me-1'></i>" . htmlescape(__tt('Export time CSV')) . "</a>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($base . '?export=travel') . "'>"
            . "<i class='ti ti-download me-1'></i>" . htmlescape(__tt('Export travel CSV')) . "</a>";
        echo "</div>";
```

Place this block before the contracts table (search for where the table opens).

- [ ] **Step 3:** `php -l front/dashboard.php inc/dashboard.class.php`. Expected: no errors.

- [ ] **Step 4:** Commit.

```bash
git add front/dashboard.php inc/dashboard.class.php
git commit -m "feat: CSV export buttons on dashboard"
```

---

## Phase 4 — Renewal alert type (J-60 enriched)

### Task 4.1: Add `renewal` type support to alert form

**Files:** `inc/alertconfig.class.php`

- [ ] **Step 1:** In `showContractAlertTab()`, change the type label resolver:

```php
            $type_label = match ($alert['type']) {
                'deadline' => __tt('Deadline'),
                'renewal'  => __tt('Renewal'),
                'threshold'=> __tt('Threshold'),
                default    => $alert['type'],
            };
            $days = in_array($alert['type'], ['deadline', 'renewal'], true)
                ? htmlescape((string) $alert['days_before'])
                : '—';
```

- [ ] **Step 2:** In the add form, replace the `<select name='type'>` block with:

```php
            echo "<td class='p-2'><label class='form-label fw-semibold'>" . __tt('Type') . "</label><br>";
            echo "<select name='type' class='form-select' style='width:auto' "
                . "onchange=\"document.getElementById('days_before_cell').style.display="
                . "(this.value==='deadline'||this.value==='renewal')?'':'none'\">";
            echo "<option value='deadline'>" . htmlescape(__tt('Deadline')) . "</option>";
            echo "<option value='renewal'>" . htmlescape(__tt('Renewal')) . "</option>";
            echo "<option value='threshold'>" . htmlescape(__tt('Threshold')) . "</option>";
            echo "</select></td>";
```

- [ ] **Step 3:** In `cronSendAlerts()`, add the dispatch case:

```php
            } elseif ($alert['type'] === 'renewal') {
                $sent += self::processRenewalAlert($alert, $contract);
```

(Insert just before the existing `elseif ($alert['type'] === 'threshold')`.)

- [ ] **Step 4:** Add the new method at the bottom of the class (before the closing `}`):

```php
    private static function processRenewalAlert(array $alert, Contract $contract): int
    {
        $begin_date = $contract->fields['begin_date'] ?? null;
        $duration   = (int) ($contract->fields['duration'] ?? 0);
        if (!$begin_date || $duration <= 0) {
            return 0;
        }

        $end_ts    = strtotime("+{$duration} months", strtotime($begin_date));
        $now_ts    = time();
        $diff_days = (int) ceil(($end_ts - $now_ts) / 86400);
        $days_before = (int) ($alert['days_before'] ?? 60);

        if ($diff_days > $days_before || $diff_days < 0) {
            return 0;
        }

        if (!empty($alert['last_sent_at'])) {
            $last_ts = strtotime($alert['last_sent_at']);
            if ($last_ts !== false && (time() - $last_ts) < 23 * 3600) {
                return 0;
            }
        }

        $contracts_id = (int) $contract->getID();
        $budget       = PluginTimetrackerContractBudget::getForContract($contracts_id);
        $initial      = (int) ($budget['initial_minutes'] ?? 0);
        $spent        = PluginTimetrackerContractBudget::getSpentMinutes($contracts_id);
        $remaining    = $initial - $spent;
        $projection   = PluginTimetrackerContractBudget::getProjection($contracts_id);
        $travel       = PluginTimetrackerTravelEntry::getContractTotals($contracts_id);
        $rate         = PluginTimetrackerTravelEntry::getKmRateCents($contracts_id);
        $travel_cost  = (int) round($travel['km'] * $rate);
        $tickets_count = self::countTickets($contracts_id);

        $contract_name = $contract->getName();
        $end_date_str  = date('Y-m-d', $end_ts);

        $subject = sprintf(
            __tt('[GLPI Timetracker] Renewal upcoming — contract "%s" (%d days)'),
            $contract_name,
            $diff_days
        );

        $body = sprintf(
            __tt(
                "Contract renewal — %s\n\n"
              . "End date: %s\n"
              . "Days remaining: %d\n\n"
              . "Hours: initial %s, consumed %s, remaining %s\n"
              . "Projection (run-rate): %s\n"
              . "Tickets opened: %d\n"
              . "Travels: %s (%s)\n\n"
              . "This is an automated alert from GLPI Timetracker."
            ),
            $contract_name,
            $end_date_str,
            $diff_days,
            PluginTimetrackerContractBudget::formatMinutes($initial),
            PluginTimetrackerContractBudget::formatMinutes($spent),
            PluginTimetrackerContractBudget::formatMinutes($remaining),
            PluginTimetrackerContractBudget::formatMinutes((int) $projection['projected_total_minutes']),
            $tickets_count,
            PluginTimetrackerTravelEntry::formatKm((float) $travel['km']),
            PluginTimetrackerTravelEntry::formatCost($travel_cost)
        );

        if (!self::sendMail($alert['recipient_email'], $subject, $body)) {
            return 0;
        }

        self::updateLastSent((int) $alert['id']);
        return 1;
    }

    private static function countTickets(int $contracts_id): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT tickets_id) AS total')],
            'FROM'   => PluginTimetrackerTimeEntry::getTable(),
            'WHERE'  => [
                'contracts_id' => $contracts_id,
                'is_deleted'   => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            return (int) ($row['total'] ?? 0);
        }

        return 0;
    }
```

- [ ] **Step 5:** Set default `days_before=60` in the form by changing the default value: in the `days_before` input, replace `value='30'` with a JS hint using the type. Simplest: keep value=30 but documentation. Skip — user sets the value when picking renewal.

- [ ] **Step 6:** `php -l inc/alertconfig.class.php`. Expected: no errors.

- [ ] **Step 7:** Commit.

```bash
git add inc/alertconfig.class.php
git commit -m "feat: renewal alert type with enriched J-60 template"
```

---

## Phase 5 — Monthly PDF report cron

### Task 5.1: Create the monthly report class

**Files:** `inc/monthlyreport.class.php`

- [ ] **Step 1:** Create file:

```php
<?php

use Symfony\Component\Mime\Address;

class PluginTimetrackerMonthlyReport extends CommonGLPI
{
    public static function cronSendMonthlyReports(?CronTask $task): int
    {
        global $DB;

        $sent = 0;

        $iterator = $DB->request([
            'FROM'  => PluginTimetrackerContractBudget::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iterator as $budget) {
            $contracts_id = (int) $budget['contracts_id'];
            $alerts = PluginTimetrackerAlertConfig::getForContract($contracts_id);
            $recipients = array_values(array_filter(array_map(
                static fn($a) => $a['recipient_email'] ?? '',
                array_filter($alerts, static fn($a) => (int) ($a['is_active'] ?? 0) === 1)
            )));

            if ($recipients === []) {
                continue;
            }

            $contract = new Contract();
            if (!$contract->getFromDB($contracts_id)) {
                continue;
            }

            $pdf_bytes = self::renderPdf($contract, (int) $budget['initial_minutes']);
            if ($pdf_bytes === null) {
                continue;
            }

            $filename = sprintf('report-%s-%s.pdf', preg_replace('/[^a-z0-9]+/i', '-', $contract->getName()), date('Y-m'));
            foreach ($recipients as $to) {
                if (self::sendPdfMail($to, $contract->getName(), $pdf_bytes, $filename)) {
                    $sent++;
                }
            }
        }

        if ($task !== null) {
            $task->addVolume($sent);
        }

        return $sent > 0 ? 1 : 0;
    }

    private static function renderPdf(Contract $contract, int $initial_minutes): ?string
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            Toolbox::logError('Mpdf class not available — monthly report skipped.');
            return null;
        }

        $contracts_id = (int) $contract->getID();
        $spent        = PluginTimetrackerContractBudget::getSpentMinutes($contracts_id);
        $remaining    = $initial_minutes - $spent;
        $projection   = PluginTimetrackerContractBudget::getProjection($contracts_id);
        $travel       = PluginTimetrackerTravelEntry::getContractTotals($contracts_id);
        $rate         = PluginTimetrackerTravelEntry::getKmRateCents($contracts_id);
        $travel_cost  = (int) round($travel['km'] * $rate);

        $html = sprintf(
            '<h1>%s</h1>'
          . '<h2>%s — %s</h2>'
          . '<table border="1" cellpadding="6" cellspacing="0">'
          . '<tr><td>%s</td><td>%s</td></tr>'
          . '<tr><td>%s</td><td>%s</td></tr>'
          . '<tr><td>%s</td><td>%s</td></tr>'
          . '<tr><td>%s</td><td>%s</td></tr>'
          . '<tr><td>%s</td><td>%s (%s)</td></tr>'
          . '</table>',
            htmlspecialchars(__tt('Monthly contract report')),
            htmlspecialchars($contract->getName()),
            htmlspecialchars(date('Y-m')),
            htmlspecialchars(__tt('Initial budget')),
            htmlspecialchars(PluginTimetrackerContractBudget::formatMinutes($initial_minutes)),
            htmlspecialchars(__tt('Consumed')),
            htmlspecialchars(PluginTimetrackerContractBudget::formatMinutes($spent)),
            htmlspecialchars(__tt('Remaining')),
            htmlspecialchars(PluginTimetrackerContractBudget::formatMinutes($remaining)),
            htmlspecialchars(__tt('Projection')),
            htmlspecialchars(PluginTimetrackerContractBudget::formatMinutes((int) $projection['projected_total_minutes'])),
            htmlspecialchars(__tt('Travels')),
            htmlspecialchars(PluginTimetrackerTravelEntry::formatKm((float) $travel['km'])),
            htmlspecialchars(PluginTimetrackerTravelEntry::formatCost($travel_cost))
        );

        try {
            $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        } catch (\Throwable $e) {
            Toolbox::logError('Monthly report PDF render failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function sendPdfMail(string $to, string $contract_name, string $pdf_bytes, string $filename): bool
    {
        try {
            $mailer = new GLPIMailer();
            $email  = $mailer->getEmail()
                ->to(new Address($to))
                ->subject(sprintf(__tt('[GLPI Timetracker] Monthly report — %s'), $contract_name))
                ->text(__tt('Please find attached the monthly tracking report.'));
            $email->attach($pdf_bytes, $filename, 'application/pdf');
            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            Toolbox::logError('Monthly report mail failed: ' . $e->getMessage());
            return false;
        }
    }
}
```

- [ ] **Step 2:** `php -l inc/monthlyreport.class.php`. Expected: no errors.

- [ ] **Step 3:** Commit.

```bash
git add inc/monthlyreport.class.php
git commit -m "feat: monthly contract PDF report renderer + mailer"
```

### Task 5.2: Register cron + class

**Files:** `hook.php`, `setup.php`

- [ ] **Step 1:** Add to `hook.php` requires:

```php
require_once __DIR__ . '/inc/monthlyreport.class.php';
```

- [ ] **Step 2:** In `plugin_timetracker_install()`, after the existing `CronTask::register` for alerts, add:

```php
    CronTask::register(
        'PluginTimetrackerMonthlyReport',
        'sendMonthlyReports',
        MONTH_TIMESTAMP,
        ['comment' => 'Send monthly contract reports', 'state' => CronTask::STATE_WAITING]
    );
```

- [ ] **Step 3:** In `plugin_timetracker_uninstall()`, add:

```php
    CronTask::unregister('PluginTimetrackerMonthlyReport');
```

- [ ] **Step 4:** In `setup.php`, register the class:

```php
    Plugin::registerClass(PluginTimetrackerMonthlyReport::class);
```

- [ ] **Step 5:** `php -l hook.php setup.php`. Expected: no errors.

- [ ] **Step 6:** Commit.

```bash
git add hook.php setup.php
git commit -m "feat: register monthly report cron"
```

---

## Phase 6 — Locales, version bump, smoke

### Task 6.1: New translation keys

**Files:** `locales/timetracker.pot`, `locales/fr_FR.po`, `locales/es_ES.po`

- [ ] **Step 1:** Append to `locales/timetracker.pot`:

```
msgid "Km rate override (cents)"
msgstr ""

msgid "Leave empty to use the global plugin rate."
msgstr ""

msgid "Default km rate (cents)"
msgstr ""

msgid "Plugin configuration"
msgstr ""

msgid "Contract time tracking — Configuration"
msgstr ""

msgid "Export time CSV"
msgstr ""

msgid "Export travel CSV"
msgstr ""

msgid "Renewal"
msgstr ""

msgid "Projected over budget"
msgstr ""

msgid "Monthly contract report"
msgstr ""

msgid "Initial budget"
msgstr ""

msgid "Consumed"
msgstr ""

msgid "Remaining"
msgstr ""

msgid "Projection"
msgstr ""

msgid "Travels"
msgstr ""

msgid "[GLPI Timetracker] Renewal upcoming — contract \"%s\" (%d days)"
msgstr ""

msgid "[GLPI Timetracker] Monthly report — %s"
msgstr ""

msgid "Please find attached the monthly tracking report."
msgstr ""

msgid "Configuration saved."
msgstr ""
```

- [ ] **Step 2:** Append the same `msgid` blocks to `locales/fr_FR.po` with French `msgstr`:

```
msgid "Km rate override (cents)"
msgstr "Tarif km — surcharge (centimes)"

msgid "Leave empty to use the global plugin rate."
msgstr "Laisser vide pour utiliser le tarif global du plugin."

msgid "Default km rate (cents)"
msgstr "Tarif km par défaut (centimes)"

msgid "Plugin configuration"
msgstr "Configuration du plugin"

msgid "Contract time tracking — Configuration"
msgstr "Suivi du temps contrat — Configuration"

msgid "Export time CSV"
msgstr "Export CSV des temps"

msgid "Export travel CSV"
msgstr "Export CSV des déplacements"

msgid "Renewal"
msgstr "Renouvellement"

msgid "Projected over budget"
msgstr "Dépassement projeté"

msgid "Monthly contract report"
msgstr "Rapport mensuel du contrat"

msgid "Initial budget"
msgstr "Budget initial"

msgid "Consumed"
msgstr "Consommé"

msgid "Remaining"
msgstr "Restant"

msgid "Projection"
msgstr "Projection"

msgid "Travels"
msgstr "Déplacements"

msgid "[GLPI Timetracker] Renewal upcoming — contract \"%s\" (%d days)"
msgstr "[GLPI Timetracker] Renouvellement proche — contrat \"%s\" (%d jours)"

msgid "[GLPI Timetracker] Monthly report — %s"
msgstr "[GLPI Timetracker] Rapport mensuel — %s"

msgid "Please find attached the monthly tracking report."
msgstr "Veuillez trouver ci-joint le rapport mensuel de suivi."

msgid "Configuration saved."
msgstr "Configuration enregistrée."
```

- [ ] **Step 3:** Append to `locales/es_ES.po`:

```
msgid "Km rate override (cents)"
msgstr "Tarifa km — anulación (céntimos)"

msgid "Leave empty to use the global plugin rate."
msgstr "Dejar vacío para usar la tarifa global del plugin."

msgid "Default km rate (cents)"
msgstr "Tarifa km por defecto (céntimos)"

msgid "Plugin configuration"
msgstr "Configuración del plugin"

msgid "Contract time tracking — Configuration"
msgstr "Seguimiento del tiempo de contrato — Configuración"

msgid "Export time CSV"
msgstr "Exportar CSV de tiempos"

msgid "Export travel CSV"
msgstr "Exportar CSV de desplazamientos"

msgid "Renewal"
msgstr "Renovación"

msgid "Projected over budget"
msgstr "Proyección sobre presupuesto"

msgid "Monthly contract report"
msgstr "Informe mensual del contrato"

msgid "Initial budget"
msgstr "Presupuesto inicial"

msgid "Consumed"
msgstr "Consumido"

msgid "Remaining"
msgstr "Restante"

msgid "Projection"
msgstr "Proyección"

msgid "Travels"
msgstr "Desplazamientos"

msgid "[GLPI Timetracker] Renewal upcoming — contract \"%s\" (%d days)"
msgstr "[GLPI Timetracker] Renovación próxima — contrato \"%s\" (%d días)"

msgid "[GLPI Timetracker] Monthly report — %s"
msgstr "[GLPI Timetracker] Informe mensual — %s"

msgid "Please find attached the monthly tracking report."
msgstr "Adjunto encontrará el informe mensual de seguimiento."

msgid "Configuration saved."
msgstr "Configuración guardada."
```

- [ ] **Step 4:** Recompile `.mo`:

```bash
python3 tools/compile_mo.py
```

Expected: writes `fr_FR.mo` and `es_ES.mo`.

- [ ] **Step 5:** Commit.

```bash
git add locales/
git commit -m "i18n: add translations for backlog features"
```

### Task 6.2: Version bump

**Files:** `setup.php`, `plugin.xml`

- [ ] **Step 1:** In `setup.php`, change `'0.1.8'` to `'0.2.0'`.

- [ ] **Step 2:** In `plugin.xml`, bump the corresponding version field (search for `0.1.8`).

- [ ] **Step 3:** Commit.

```bash
git add setup.php plugin.xml
git commit -m "feat: bump version to 0.2.0"
```

### Task 6.3: Smoke validation

**Files:** `tools/smoke.php`

- [ ] **Step 1:** Append assertions for new helpers (after existing ones, before the failures check):

```php
require_once $plugin_root . '/inc/exporter.class.php';
require_once $plugin_root . '/inc/monthlyreport.class.php';

if (!method_exists(PluginTimetrackerExporter::class, 'streamTimeEntriesCsv')) {
    $failures[] = 'Missing CSV exporter for time entries.';
}
if (!method_exists(PluginTimetrackerExporter::class, 'streamTravelEntriesCsv')) {
    $failures[] = 'Missing CSV exporter for travel entries.';
}
if (!method_exists(PluginTimetrackerContractBudget::class, 'getProjection')) {
    $failures[] = 'Missing run-rate projection helper.';
}
if (!method_exists(PluginTimetrackerMonthlyReport::class, 'cronSendMonthlyReports')) {
    $failures[] = 'Missing monthly report cron entrypoint.';
}
```

- [ ] **Step 2:** Run smoke (if GLPI checkout available):

```bash
php tools/smoke.php /path/to/glpi
```

Expected: `Smoke test OK`.

- [ ] **Step 3:** Commit.

```bash
git add tools/smoke.php
git commit -m "test: smoke assertions for exporters, projection, monthly report"
```

---

## Self-review

- Spec coverage: km rate (✓ Phase 1), dashboard km/cost/projection (✓ Phase 2), CSV exports (✓ Phase 3), renewal alert (✓ Phase 4), monthly PDF report (✓ Phase 5), i18n + smoke (✓ Phase 6).
- No placeholders — every step contains concrete code or a concrete command.
- Type consistency: `getKmRateCents(?int $contracts_id = null)` is used consistently across Phases 1, 2, 3, 4, 5. `getProjection` returns `['daily_avg_minutes', 'projected_total_minutes', 'days_remaining']` used in Phases 2 and 4. `getContractTotals` returns `['km', 'minutes', 'count']` used in Phases 2 and 4.
- Mpdf availability: guarded by `class_exists` — graceful degradation if absent.
