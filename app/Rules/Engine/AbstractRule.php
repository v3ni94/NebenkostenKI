<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Basisklasse aller Pruefregeln.
 *
 * Die Metadaten werden als typisierte Klassenkonstanten deklariert. Dadurch
 * bleibt jede Regelklasse kurz und die Metadaten sind ohne Instanziierung
 * lesbar.
 *
 * Der frueheste Gueltigkeitsbeginn ist eine Produktentscheidung: die
 * Anwendung unterstuetzt Abrechnungszeitraeume ab 2020. Er ist keine Aussage
 * ueber den Beginn einer Rechtslage.
 */
abstract class AbstractRule implements Rule
{
    /**
     * Fruehester von der Anwendung unterstuetzter Abrechnungszeitraum.
     */
    public const string EARLIEST_VALID_FROM = '2020-01-01';

    protected const string CODE = '';

    protected const string VERSION = '1.0.0';

    protected const string TITLE = '';

    protected const string DESCRIPTION = '';

    protected const string REFERENCE = '';

    protected const string PASSED_DESCRIPTION = '';

    protected const string VALID_FROM = self::EARLIEST_VALID_FROM;

    protected const ?string VALID_TO = null;

    protected const bool USER_RESOLVABLE = true;

    public function code(): string
    {
        return static::CODE;
    }

    public function version(): string
    {
        return static::VERSION;
    }

    public function title(): string
    {
        return static::TITLE;
    }

    public function description(): string
    {
        return static::DESCRIPTION;
    }

    public function reference(): string
    {
        return static::REFERENCE;
    }

    public function passedDescription(): string
    {
        return static::PASSED_DESCRIPTION;
    }

    public function validFrom(): DateTimeImmutable
    {
        return self::day(static::VALID_FROM);
    }

    public function validTo(): ?DateTimeImmutable
    {
        return static::VALID_TO === null ? null : self::day(static::VALID_TO);
    }

    public function isEffectiveOn(DateTimeImmutable $date): bool
    {
        $day = self::day($date->format('Y-m-d'));

        if ($day < $this->validFrom()) {
            return false;
        }

        $validTo = $this->validTo();

        return ! ($validTo instanceof DateTimeImmutable) || $day <= $validTo;
    }

    public function isUserResolvable(): bool
    {
        return static::USER_RESOLVABLE;
    }

    /**
     * Kalendertag ohne Uhrzeit, damit Vergleiche unabhaengig von der Zeitzone
     * eindeutig bleiben.
     */
    private static function day(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso.' 00:00:00', new DateTimeZone('UTC'));
    }
}
