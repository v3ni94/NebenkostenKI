<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\Payment\Dto\RecoveryReport;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Use Case: die Finalisierung eines bezahlten Laufs erneut ausfuehren.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Wiederholt wird ausschliesslich fuer Laeufe mit bestaetigter Zahlung
 *     (paid_at gesetzt) in FAILED oder haengend in PAID. Die Statusmaschine
 *     erlaubt genau diese Wege nach FINALIZING (Regel 4 der Statusmaschine).
 *  2. Es wird nichts erfunden und nichts ueberschrieben: Die Finalisierung
 *     erzeugt aus dem gesperrten Berechnungsstand, die Rechnung ist idempotent.
 *  3. Jeder Versuch wird protokolliert. Ein erneuter Fehlschlag laesst den Lauf
 *     in FAILED und ist damit fuer den naechsten Versuch sichtbar.
 *  4. Aufrufer sind der Adminbereich (Handlung mit Akteur), der Befehl
 *     smartabrechnen:retry-finalization und der Zeitplan (ohne Akteur).
 */
final class RetryFinalization
{
    public function __construct(
        private readonly FinalizeBillingRun $finalize,
        private readonly PaymentRecoveryOverview $overview,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Einzelner Lauf, etwa aus dem Adminbereich.
     *
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public function one(BillingRun $billingRun, ?User $actor = null): ?string
    {
        if (! $this->isRetryable($billingRun)) {
            return 'Der Abrechnungslauf ist in seinem aktuellen Zustand nicht erneut finalisierbar. '
                .'Erforderlich sind eine bestätigte Zahlung und der Status Fehlgeschlagen oder Bezahlt.';
        }

        $this->audit->record(
            action: 'billing_run.finalization_retried',
            subject: $billingRun,
            actor: $actor,
            organization: is_string($billingRun->getAttribute('organization_id'))
                ? (string) $billingRun->getAttribute('organization_id')
                : null,
            metadata: ['von' => $this->statusValue($billingRun)],
        );

        try {
            ($this->finalize)($billingRun->refresh(), $actor);
        } catch (Throwable $exception) {
            Log::warning('Die erneute Finalisierung ist fehlgeschlagen.', [
                'abrechnungslauf' => (string) $billingRun->getKey(),
                'fehler' => $exception->getMessage(),
            ]);

            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Alle offenen Laeufe, etwa aus Befehl oder Zeitplan.
     */
    public function all(int $limit = 25): RecoveryReport
    {
        $report = new RecoveryReport;

        foreach ($this->overview->unfinalizedPaidRuns($limit) as $billingRun) {
            $error = $this->one($billingRun);

            if ($error === null) {
                $report->succeeded((string) $billingRun->getKey());
            } else {
                $report->failed((string) $billingRun->getKey(), $error);
            }
        }

        return $report;
    }

    public function isRetryable(BillingRun $billingRun): bool
    {
        if ($billingRun->getAttribute('paid_at') === null) {
            return false;
        }

        return in_array($billingRun->getAttribute('status'), [
            BillingRunStatus::FAILED,
            BillingRunStatus::PAID,
        ], true);
    }

    private function statusValue(BillingRun $billingRun): ?string
    {
        $status = $billingRun->getAttribute('status');

        return $status instanceof BillingRunStatus ? $status->value : null;
    }
}
