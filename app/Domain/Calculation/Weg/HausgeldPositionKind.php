<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\AllocabilityStatus;

/**
 * Art einer Position der WEG-Hausgeld- beziehungsweise Einzelabrechnung.
 *
 * Abschnitt 7.2 des Pflichtenhefts nennt die Werte, die NICHT einfach als
 * Mietnebenkosten übernommen werden dürfen. Sie sind hier als eigene Arten
 * abgebildet und werden vom HausgeldCostExtractor verbindlich
 * ausgeschlossen.
 */
enum HausgeldPositionKind: string
{
    /** Laufende Betriebskostenart, grundsätzlich umlagefähig. */
    case OPERATING_COST = 'OPERATING_COST';

    /** Heiz- und Warmwasserkosten aus der WEG-Abrechnung. */
    case HEATING_COST = 'HEATING_COST';

    /** Grundsteuer, sofern in der WEG-Abrechnung enthalten. */
    case PROPERTY_TAX = 'PROPERTY_TAX';

    case HOUSE_MONEY_PREPAYMENT = 'HOUSE_MONEY_PREPAYMENT';
    case SETTLEMENT_BALANCE = 'SETTLEMENT_BALANCE';
    case RESERVE_CONTRIBUTION = 'RESERVE_CONTRIBUTION';
    case RESERVE_WITHDRAWAL = 'RESERVE_WITHDRAWAL';
    case ADMINISTRATION_COST = 'ADMINISTRATION_COST';
    case BANK_AND_FINANCING_COST = 'BANK_AND_FINANCING_COST';
    case MAINTENANCE_AND_REPAIR = 'MAINTENANCE_AND_REPAIR';
    case LEGAL_COST = 'LEGAL_COST';
    case UNLABELLED_COLLECTIVE_POSITION = 'UNLABELLED_COLLECTIVE_POSITION';

    public function label(): string
    {
        return match ($this) {
            self::OPERATING_COST => 'laufende Betriebskosten',
            self::HEATING_COST => 'Heiz- und Warmwasserkosten',
            self::PROPERTY_TAX => 'Grundsteuer',
            self::HOUSE_MONEY_PREPAYMENT => 'Hausgeldvorauszahlungen',
            self::SETTLEMENT_BALANCE => 'Abrechnungsspitze beziehungsweise Guthaben oder Nachzahlung der WEG',
            self::RESERVE_CONTRIBUTION => 'Zuführung zur Erhaltungsrücklage',
            self::RESERVE_WITHDRAWAL => 'Entnahme aus der Rücklage',
            self::ADMINISTRATION_COST => 'Verwalterkosten',
            self::BANK_AND_FINANCING_COST => 'Bank- und Finanzierungskosten',
            self::MAINTENANCE_AND_REPAIR => 'Instandhaltung, Instandsetzung und Reparaturen',
            self::LEGAL_COST => 'Rechts- und Prozesskosten',
            self::UNLABELLED_COLLECTIVE_POSITION => 'nicht näher bezeichnete Sammelposition',
        };
    }

    /**
     * Ist die Art nach Abschnitt 7.2 verbindlich von der Mieterumlage
     * ausgeschlossen?
     */
    public function isExcludedByRule(): bool
    {
        return match ($this) {
            self::OPERATING_COST, self::HEATING_COST, self::PROPERTY_TAX => false,
            default => true,
        };
    }

    /**
     * Begründung des Ausschlusses für Prüfbericht und Eigentümerübersicht.
     */
    public function exclusionReason(): string
    {
        return match ($this) {
            self::HOUSE_MONEY_PREPAYMENT => 'Hausgeldvorauszahlungen sind keine Betriebskosten, sondern Zahlungen des '
                .'Eigentümers an die WEG.',
            self::SETTLEMENT_BALANCE => 'Die Abrechnungsspitze ist ein Saldo gegenüber der WEG und keine '
                .'umlagefähige Kostenart.',
            self::RESERVE_CONTRIBUTION => 'Die Zuführung zur Erhaltungsrücklage ist keine Betriebskostenart.',
            self::RESERVE_WITHDRAWAL => 'Eine Entnahme aus der Rücklage wird ohne Prüfung der zugrunde liegenden '
                .'Kostenart nicht übernommen.',
            self::ADMINISTRATION_COST => 'Verwaltungskosten sind nicht umlagefähig.',
            self::BANK_AND_FINANCING_COST => 'Bank- und Finanzierungskosten sind nicht umlagefähig.',
            self::MAINTENANCE_AND_REPAIR => 'Instandhaltung, Instandsetzung und Reparaturen sind nicht umlagefähig.',
            self::LEGAL_COST => 'Rechts- und Prozesskosten sind nicht umlagefähig.',
            self::UNLABELLED_COLLECTIVE_POSITION => 'Nicht näher bezeichnete Sammelpositionen werden ohne '
                .'Aufschlüsselung nicht übernommen.',
            self::OPERATING_COST, self::HEATING_COST, self::PROPERTY_TAX => '',
        };
    }

    /**
     * Umlagestatus, mit dem die Position in die Berechnung eingeht.
     */
    public function allocabilityStatus(): AllocabilityStatus
    {
        return match ($this) {
            self::OPERATING_COST, self::HEATING_COST, self::PROPERTY_TAX => AllocabilityStatus::ALLOCABLE,
            self::UNLABELLED_COLLECTIVE_POSITION, self::RESERVE_WITHDRAWAL => AllocabilityStatus::REVIEW_REQUIRED,
            default => AllocabilityStatus::NOT_ALLOCABLE,
        };
    }
}
