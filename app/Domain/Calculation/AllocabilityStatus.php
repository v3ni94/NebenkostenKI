<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

/**
 * Umlagestatus einer Kostenart (Pflichtenheft Abschnitt 12.1 und 12.2).
 *
 * Domain-eigenes Enum; die Persistenzschicht bildet ihre Spaltenwerte darauf
 * ab. Die Engine trifft KEINE juristische Freigabe: der Status steuert nur,
 * ob eine Position standardmäßig in die Mieterumlage einbezogen wird.
 *
 * ALLOCABLE        Standardmäßig umlagefähige Betriebskostenart.
 * NOT_ALLOCABLE    Standardmäßig nicht umlagefähig (Verwaltung,
 *                  Instandhaltung, Reparatur, Bank- und Finanzierungskosten,
 *                  Rechtskosten, Rücklagenzuführung).
 * REVIEW_REQUIRED  Prüfpflichtig, z. B. unbezeichnete Sammelpositionen oder
 *                  "sonstige Betriebskosten" ohne erkannte Vertragsgrundlage.
 */
enum AllocabilityStatus: string
{
    case ALLOCABLE = 'ALLOCABLE';
    case NOT_ALLOCABLE = 'NOT_ALLOCABLE';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /**
     * Wird die Position ohne ausdrückliche Nutzerentscheidung umgelegt?
     */
    public function isAllocatedByDefault(): bool
    {
        return $this === self::ALLOCABLE;
    }

    public function label(): string
    {
        return match ($this) {
            self::ALLOCABLE => 'umlagefähig',
            self::NOT_ALLOCABLE => 'nicht umlagefähig',
            self::REVIEW_REQUIRED => 'prüfpflichtig',
        };
    }
}
