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

            $safe_slug = preg_replace('/[^a-z0-9]+/i', '-', $contract->getName()) ?: 'contract';
            $filename = sprintf('report-%s-%s.pdf', $safe_slug, date('Y-m'));
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
