<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Models\BillingRun;
use App\Models\TemporaryUpload;
use App\Services\Storage\UploadLimits;

/**
 * Volumenpruefung je Abrechnungslauf (Abschnitt 6.1).
 *
 * Verbraucht gilt: die bereits verbuchten Bytes des Laufs plus die angekuendigten
 * Bytes aller noch offenen Uploads. Ohne die offenen Uploads koennte ein Nutzer
 * das Laufslimit umgehen, indem er viele Uploads gleichzeitig startet.
 *
 * Verbucht wird erst die tatsaechlich zusammengesetzte Groesse, damit ein
 * abgebrochener Upload das Volumen nicht dauerhaft belastet.
 */
final class UploadQuota
{
    public function __construct(private readonly UploadLimits $limits) {}

    public function committedBytes(BillingRun $billingRun): int
    {
        return (int) $billingRun->getAttribute('uploaded_bytes');
    }

    /**
     * Angekuendigte Bytes der noch offenen Uploads dieses Laufs.
     */
    public function reservedBytes(BillingRun $billingRun): int
    {
        return (int) TemporaryUpload::query()
            ->where('is_tombstone', false)
            ->whereIn(
                'document_id',
                fn ($query) => $query->select('id')
                    ->from('documents')
                    ->where('billing_run_id', $billingRun->getKey())
            )
            ->sum('byte_size');
    }

    public function usedBytes(BillingRun $billingRun): int
    {
        return $this->committedBytes($billingRun) + $this->reservedBytes($billingRun);
    }

    public function remainingBytes(BillingRun $billingRun): int
    {
        return max(0, $this->limits->maxRunBytes - $this->usedBytes($billingRun));
    }

    public function fitsInRun(BillingRun $billingRun, int $additionalBytes): bool
    {
        return $this->usedBytes($billingRun) + $additionalBytes <= $this->limits->maxRunBytes;
    }

    /**
     * Verbucht die tatsaechliche Groesse einer zusammengesetzten Datei.
     */
    public function commit(BillingRun $billingRun, int $bytes): void
    {
        $billingRun->forceFill([
            'uploaded_bytes' => $this->committedBytes($billingRun) + max(0, $bytes),
        ])->save();
    }
}
