<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Application\Account\AuditRecorder;
use App\Models\BillingRun;
use App\Models\BillingRunVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Korrektur eines Abrechnungslaufs als neue Version.
 *
 * VERBINDLICH (Masterprompt 11.5, ARCHITECTURE.md Grundsatz 9): Eine Korrektur
 * aendert einen bestehenden Stand niemals. Sie erzeugt eine neue Version, der
 * bisherige Stand bleibt unveraendert und reproduzierbar. Das gilt besonders
 * nach der Zahlung, weil der bezahlte Calculation Snapshot gesperrt ist.
 *
 * Diese Klasse schreibt ausschliesslich die Eingabeversion. Die erneute
 * Berechnung, der neue Calculation Snapshot und eine etwaige Stornorechnung
 * gehoeren in die Berechnungs- und Zahlungspakete.
 */
class RecordCorrection
{
    public const AUDIT_ACTION = 'billing_run.correction_recorded';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $payload  abrechnungsrelevante Nutzereingaben
     */
    public function handle(
        BillingRun $billingRun,
        array $payload,
        ?User $actor = null,
        ?string $reason = null,
    ): BillingRunVersion {
        /** @var BillingRunVersion $version */
        $version = DB::transaction(function () use ($billingRun, $payload, $actor, $reason): BillingRunVersion {
            // Zeilensperre auf dem Lauf, damit zwei gleichzeitige Korrekturen
            // nicht dieselbe Versionsnummer vergeben.
            BillingRun::query()
                ->whereKey($billingRun->getKey())
                ->lockForUpdate()
                ->first();

            $letzte = BillingRunVersion::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->max('version_number');

            $naechste = is_numeric($letzte) ? ((int) $letzte) + 1 : 1;

            $kodiert = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            /** @var BillingRunVersion $neu */
            $neu = BillingRunVersion::query()->create([
                'organization_id' => $billingRun->getAttribute('organization_id'),
                'billing_run_id' => $billingRun->getKey(),
                'version_number' => $naechste,
                'payload' => $payload,
                'payload_hash' => hash('sha256', $kodiert),
                'reason' => $reason,
                'created_by_user_id' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            return $neu;
        });

        $organizationId = $billingRun->getAttribute('organization_id');
        $versionsnummer = $version->getAttribute('version_number');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: ['version' => is_int($versionsnummer) ? $versionsnummer : null],
            reason: $reason,
        );

        return $version;
    }
}
