<?php

declare(strict_types=1);

namespace App\Services\Storage\Exceptions;

use App\Services\Storage\UploadErrorCode;
use RuntimeException;

/**
 * Fachliche Ablehnung einer hochgeladenen Datei.
 *
 * DATENSCHUTZ: Die Ausnahme fuehrt ausschliesslich einen Fehlercode und den
 * dazugehoerigen allgemeinen deutschen Hinweistext. Sie enthaelt niemals
 * Dateiinhalte, Dateinamen, Pfade, OCR-Text oder Rohantworten, weil Ausnahmen
 * in Logs, Error Monitoring und Fehlerseiten sichtbar werden.
 */
class UploadRejectedException extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $technicalContext  nur Zahlen und kurze
     *                                                       technische Kennungen, niemals Inhalte
     */
    final public function __construct(
        public readonly UploadErrorCode $errorCode,
        private readonly array $technicalContext = [],
    ) {
        parent::__construct($errorCode->message());
    }

    public static function because(UploadErrorCode $code): self
    {
        return new self($code);
    }

    /**
     * @param  array<string, int|string>  $technicalContext
     */
    public static function withContext(UploadErrorCode $code, array $technicalContext): self
    {
        return new self($code, $technicalContext);
    }

    /**
     * Nur Zahlen und kurze technische Kennungen. Wird fuer Protokolle genutzt.
     *
     * @return array<string, int|string>
     */
    public function technicalContext(): array
    {
        return $this->technicalContext;
    }

    public function isPermanent(): bool
    {
        return $this->errorCode->isPermanent();
    }
}
