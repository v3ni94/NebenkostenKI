<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Support\DomainException;
use InvalidArgumentException;

/**
 * Struktureller Eingabefehler eines Abrechnungslaufs.
 *
 * Diese Fehler kann die Engine nicht durch ein Prüfergebnis auffangen; die
 * Anwendungsschicht muss die Daten vor dem Aufruf bereinigen.
 */
final class CalculationInputException extends InvalidArgumentException implements DomainException
{
    public static function duplicateUnitKey(string $unitKey): self
    {
        return new self(sprintf('Die Einheit "%s" ist mehrfach übergeben worden.', $unitKey));
    }

    public static function duplicateOccupancyKey(string $occupancyKey): self
    {
        return new self(sprintf('Der Nutzungszeitraum "%s" ist mehrfach übergeben worden.', $occupancyKey));
    }

    public static function unknownUnit(string $occupancyKey, string $unitKey): self
    {
        return new self(sprintf(
            'Der Nutzungszeitraum "%s" verweist auf die unbekannte Einheit "%s".',
            $occupancyKey,
            $unitKey
        ));
    }

    public static function noUnits(): self
    {
        return new self('Der Abrechnungslauf enthält keine Einheiten.');
    }

    public static function occupancyOutsideBillingPeriod(string $occupancyKey, DatePeriodRange $period, DatePeriodRange $billingPeriod): self
    {
        return new self(sprintf(
            'Der Nutzungszeitraum "%s" (%s) liegt vollständig außerhalb des Abrechnungszeitraums (%s).',
            $occupancyKey,
            $period->format(),
            $billingPeriod->format()
        ));
    }

    public static function unknownAllocationKey(string $costItemKey, string $allocationKeyRef): self
    {
        return new self(sprintf(
            'Für die Kostenposition "%s" ist der Verteilerschlüssel "%s" nicht übergeben worden.',
            $costItemKey,
            $allocationKeyRef
        ));
    }

    public static function duplicateCostItemKey(string $costItemKey): self
    {
        return new self(sprintf('Die Kostenposition "%s" ist mehrfach übergeben worden.', $costItemKey));
    }
}
