<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleContext;
use DateTimeImmutable;

/**
 * Eine versionierte Pruefregel.
 *
 * Jede Regel besitzt einen stabilen sprechenden Code, eine Version, eine
 * Severity, eine deutsche Beschreibung, eine fachliche Referenz als Freitext
 * und einen Gueltigkeitszeitraum. Die Pruefmethode liefert Befunde als
 * Rueckgabewert, niemals eine Exception, und schreibt nicht in die Datenbank.
 *
 * Regeltexte sind allgemeine Information und Pruefhinweis. Sie sind keine
 * Rechtsberatung im Einzelfall und enthalten keine Zusage eines Ergebnisses.
 */
interface Rule
{
    public function code(): string;

    public function version(): string;

    /**
     * Severity, mit der ein Befund dieser Regel standardmaessig gemeldet wird.
     */
    public function severity(): ValidationSeverity;

    public function title(): string;

    public function description(): string;

    /**
     * Fachliche oder rechtliche Referenz als Freitext, nur soweit gesichert.
     */
    public function reference(): string;

    /**
     * Text, der ausgegeben wird, wenn die Regel ohne Befund bleibt.
     */
    public function passedDescription(): string;

    public function validFrom(): DateTimeImmutable;

    public function validTo(): ?DateTimeImmutable;

    public function isEffectiveOn(DateTimeImmutable $date): bool;

    /**
     * Eine Warnung ist durch eine ausdrueckliche Nutzerentscheidung
     * aufloesbar. Regeln mit besonderem Schutzzweck sind nicht wegklickbar.
     */
    public function isUserResolvable(): bool;

    /**
     * @return list<RuleFinding>
     */
    public function evaluate(RuleContext $context): array;
}
