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
            $where[] = ['spent_at' => ['>=', $date_from]];
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
            $where[] = ['travel_date' => ['>=', $date_from]];
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
        $rate_cache = [];
        foreach ($iterator as $row) {
            $contract_name = $contract->getFromDB((int) $row['contracts_id']) ? $contract->getName() : '';
            $user_name     = $user->getFromDB((int) $row['users_id']) ? $user->getName() : '';
            $km            = (float) $row['km'];
            $cid           = (int) $row['contracts_id'];
            $rate          = $rate_cache[$cid] ?? ($rate_cache[$cid] = PluginTimetrackerTravelEntry::getKmRateCents($cid));
            $cost_cent     = (int) round($km * $rate);

            fputcsv($out, [
                (string) $row['travel_date'],
                (int) $row['tickets_id'],
                $cid,
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
