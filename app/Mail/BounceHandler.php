<?php

declare(strict_types=1);

namespace App\Mail;

use App\Application\Account\AuditRecorder;
use App\Enums\EmailStatus;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\User;

/**
 * Behandlung von Bounces und dauerhaften Zustellfehlern.
 *
 * Ablauf (Masterprompt 17.2):
 *
 *  1. Das letzte offene Protokoll der Adresse wird als unzustellbar markiert.
 *  2. Die Adresse wird in email_suppressions gesperrt.
 *  3. Der Vorgang wird revisionssicher protokolliert.
 *  4. Die Kontoseite zeigt danach einen Hinweis. Erinnerungen werden nicht mehr
 *     versendet, kritische Konto- und Zahlungsnachrichten weiterhin.
 *
 * Eine Beschwerde wird gleich behandelt, weil auch sie eine dauerhafte
 * Ablehnung ist.
 */
class BounceHandler
{
    public const AUDIT_ACTION = 'email.suppression_created';

    public function __construct(
        private readonly SuppressionGuard $suppression,
        private readonly AuditRecorder $audit,
    ) {}

    public function handlePermanentFailure(
        string $email,
        string $source,
        ?User $nutzer = null,
        ?string $organizationId = null,
        EmailSuppressionReason $reason = EmailSuppressionReason::BOUNCE,
    ): EmailSuppression {
        $adresse = SuppressionGuard::normalize($email);

        EmailMessage::query()
            ->where('recipient_email', $adresse)
            ->whereIn('status', [EmailStatus::WARTEND->value, EmailStatus::FEHLGESCHLAGEN->value])
            ->update([
                'status' => EmailStatus::BOUNCED->value,
                'error_code' => 'DAUERHAFT_UNZUSTELLBAR',
            ]);

        $sperre = $this->suppression->suppress(
            email: $adresse,
            reason: $reason,
            source: $source,
            note: 'Dauerhafter Zustellfehler. Es werden keine Erinnerungen mehr versendet.',
        );

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $sperre,
            actor: $nutzer,
            organization: $organizationId,
            metadata: [
                'grund' => $reason->value,
                'quelle' => $source,
            ],
        );

        return $sperre;
    }
}
