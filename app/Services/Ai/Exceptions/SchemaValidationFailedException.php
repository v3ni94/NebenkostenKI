<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use App\Services\Ai\Dto\SchemaViolation;

/**
 * Die Modellantwort war nach allen zulaessigen Reparaturversuchen nicht
 * schemakonform.
 *
 * Im Regelfall gibt die Schicht in diesem Fall ein Ergebnis mit Status
 * FEHLGESCHLAGEN zurueck und schlaegt die manuelle Erfassung vor, statt eine
 * Ausnahme zu werfen. Diese Ausnahme wird nur dort geworfen, wo der Aufrufer
 * ausdruecklich validierte Daten verlangt, also in
 * ExtractionResult::requireValidatedData().
 *
 * Die Ausnahme fuehrt nur Schemapfade und Verletzungscodes mit, niemals die
 * beanstandeten Werte oder die rohe Modellantwort.
 */
final class SchemaValidationFailedException extends AiException
{
    /** @var list<string> */
    private array $violationPaths = [];

    /** @var list<string> */
    private array $violationCodes = [];

    /**
     * @param  list<SchemaViolation>  $violations
     */
    public static function afterRetries(string $schemaKey, int $attempts, array $violations): self
    {
        $exception = new self(sprintf(
            'Schema "%s" konnte nach %d Versuchen nicht validiert werden.',
            $schemaKey,
            $attempts,
        ));

        foreach ($violations as $violation) {
            $exception->violationPaths[] = $violation->path;
            $exception->violationCodes[] = $violation->code->value;
        }

        $exception->violationPaths = array_values(array_unique($exception->violationPaths));
        $exception->violationCodes = array_values(array_unique($exception->violationCodes));

        return $exception;
    }

    /**
     * @return list<string>
     */
    public function violationPaths(): array
    {
        return $this->violationPaths;
    }

    /**
     * @return list<string>
     */
    public function violationCodes(): array
    {
        return $this->violationCodes;
    }
}
