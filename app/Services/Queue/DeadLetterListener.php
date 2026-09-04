<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ProcessingJob;

/**
 * Wird benachrichtigt, sobald ein Teiljob endgueltig in den Dead-Letter-Status
 * uebergeht, egal ob durch einen gemeldeten Fehlversuch oder durch ein
 * abgelaufenes Lease nach erschoepften Versuchen.
 *
 * Die Queue-Schicht kennt keine fachlichen Objekte. Der Abschluss des
 * zugehoerigen Dokuments, also Kennzeichnung und sofortige Loeschung der
 * Quelldaten, liegt beim Bearbeiter dieser Schnittstelle.
 */
interface DeadLetterListener
{
    public function deadLettered(ProcessingJob $job): void;
}
