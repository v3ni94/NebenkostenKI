<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

/**
 * Regelcodes der Reconciliation.
 *
 * Die Codes sind stabil und werden in validation_issues gespeichert. Sie
 * ergaenzen die Codes der Berechnungsengine (App\Domain\Calculation\Result\
 * CheckCode) und die der KI-Schicht (KI-EXT-...).
 */
final class RuleCode
{
    public const string VERSION = '1.0.0';

    /** Fehlende Pflichtangabe einer Kostenposition. */
    public const string MISSING_MANDATORY = 'REC-001';

    /** Position der WEG-Abrechnung nach Abschnitt 7.2 ausgeschlossen. */
    public const string WEG_POSITION_EXCLUDED = 'REC-002';

    /** Verwalterkennzeichnung "umlagefaehig" ohne eigene Freigabewirkung. */
    public const string WEG_MANAGER_FLAG_NOT_A_RELEASE = 'REC-003';

    /** Grundsteuer moeglicherweise doppelt erfasst. */
    public const string PROPERTY_TAX_POSSIBLE_DUPLICATE = 'REC-004';

    /** Zeitraum oder Zuordnung des Grundsteuerbescheids ist zu bestaetigen. */
    public const string PROPERTY_TAX_NEEDS_CONFIRMATION = 'REC-005';

    /** Heizkosten der WEG-Summenposition wegen externer Abrechnung nicht angesetzt. */
    public const string HEATING_DOUBLE_COUNT_PREVENTED = 'REC-006';

    /** Pruefsumme der Heizkostenabrechnung ausserhalb der Toleranz. */
    public const string HEATING_CHECKSUM_OUT_OF_TOLERANCE = 'REC-007';

    /** Moegliche Dublette auf Positionsebene. */
    public const string DUPLICATE_POSITION = 'REC-008';

    /** Rechnung und zugehoerige Gutschrift erkannt. */
    public const string CREDIT_NOTE_PAIR = 'REC-009';

    /** Unzureichende Unterlagen nach Abschnitt 7.5. */
    public const string INSUFFICIENT_DOCUMENTS = 'REC-010';

    /** Leistungszeitraum ausserhalb des Abrechnungszeitraums. */
    public const string SERVICE_PERIOD_OUTSIDE = 'REC-011';

    /** Position in einer nicht umlagefaehigen Kategorie. */
    public const string NOT_ALLOCABLE_CATEGORY = 'REC-012';

    /** Offene Kostenpositionen sperren den naechsten Schritt. */
    public const string REVIEW_INCOMPLETE = 'REC-013';

    /**
     * Alle Codes der Reconciliation. Ein erneuter Lauf raeumt die eigenen
     * offenen Aufgaben auf, bevor er neu schreibt.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MISSING_MANDATORY,
            self::WEG_POSITION_EXCLUDED,
            self::WEG_MANAGER_FLAG_NOT_A_RELEASE,
            self::PROPERTY_TAX_POSSIBLE_DUPLICATE,
            self::PROPERTY_TAX_NEEDS_CONFIRMATION,
            self::HEATING_DOUBLE_COUNT_PREVENTED,
            self::HEATING_CHECKSUM_OUT_OF_TOLERANCE,
            self::DUPLICATE_POSITION,
            self::CREDIT_NOTE_PAIR,
            self::INSUFFICIENT_DOCUMENTS,
            self::SERVICE_PERIOD_OUTSIDE,
            self::NOT_ALLOCABLE_CATEGORY,
            self::REVIEW_INCOMPLETE,
        ];
    }
}
