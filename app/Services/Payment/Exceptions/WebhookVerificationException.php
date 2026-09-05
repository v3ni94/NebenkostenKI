<?php

declare(strict_types=1);

namespace App\Services\Payment\Exceptions;

use App\Enums\WebhookSignatureStatus;
use RuntimeException;

/**
 * Die Signatur einer Providerbenachrichtigung ist nicht pruefbar.
 *
 * VERBINDLICH (Abschnitt 15.1): Eine Benachrichtigung ohne gueltige Signatur
 * wird niemals verarbeitet. Sie schaltet insbesondere keine Finalisierung frei.
 */
final class WebhookVerificationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly WebhookSignatureStatus $signatureStatus,
    ) {
        parent::__construct($message);
    }

    public static function headerMissing(): self
    {
        return new self(
            'Die Benachrichtigung enthält keine Signatur und wird nicht verarbeitet.',
            WebhookSignatureStatus::FEHLT,
        );
    }

    public static function invalid(): self
    {
        return new self(
            'Die Signatur der Benachrichtigung ist ungültig und wird nicht verarbeitet.',
            WebhookSignatureStatus::UNGUELTIG,
        );
    }

    public static function unreadablePayload(): self
    {
        return new self(
            'Die Nutzlast der Benachrichtigung ist nicht lesbar und wird nicht verarbeitet.',
            WebhookSignatureStatus::UNGUELTIG,
        );
    }
}
