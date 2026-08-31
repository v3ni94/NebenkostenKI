<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\Storage\UploadErrorCode;
use RuntimeException;

/**
 * Kontrolliertes Scheitern eines Teiljobs.
 *
 * DATENSCHUTZ: Die Ausnahme fuehrt nur einen Fehlercode und den dazugehoerigen
 * allgemeinen deutschen Hinweistext. Sie enthaelt keine Dateiinhalte, keine
 * Dateinamen und keine Rohantworten, weil last_error in der Datenbank steht
 * und im Adminbereich angezeigt wird.
 */
class JobFailedException extends RuntimeException
{
    final public function __construct(
        public readonly UploadErrorCode $errorCode,
        public readonly bool $permanent,
    ) {
        parent::__construct($errorCode->message());
    }

    /**
     * Endgueltiger Fehler. Der Job geht ohne weiteren Versuch in den
     * Dead-Letter-Status, die Quelldaten werden sofort geloescht.
     */
    public static function permanent(UploadErrorCode $code): self
    {
        return new self($code, true);
    }

    /**
     * Wiederholbarer Fehler. Der Job wird mit exponentiellem Backoff erneut
     * eingeplant, bis max_attempts erreicht ist.
     */
    public static function retryable(UploadErrorCode $code): self
    {
        return new self($code, false);
    }
}
