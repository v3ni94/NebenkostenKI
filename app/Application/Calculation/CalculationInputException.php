<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use RuntimeException;

/**
 * Fehlender oder unbrauchbarer Pflichtwert beim Aufbau der Eingabe.
 *
 * VERBINDLICH: Ein fehlender Pflichtwert wird niemals geschaetzt oder mit
 * einem Ersatzwert gefuellt. Der Aufbau bricht ab und die Oberflaeche zeigt
 * den Text dieser Ausnahme als Pruefaufgabe an. Die Texte sind deshalb in
 * deutscher Sprache und ohne technische Rohdaten formuliert.
 */
final class CalculationInputException extends RuntimeException
{
    public static function noUnits(string $propertyLabel): self
    {
        return new self(sprintf(
            'Für das Objekt %s ist keine Einheit erfasst. Ohne Einheiten ist keine Abrechnung möglich.',
            $propertyLabel
        ));
    }

    public static function missingAllocationKey(string $categoryLabel): self
    {
        return new self(sprintf(
            'Für die Kostenart %s ist kein Verteilerschlüssel festgelegt. Bitte legen Sie den Schlüssel in '
            .'Schritt 8 fest. Es wird kein Schlüssel angenommen.',
            $categoryLabel
        ));
    }

    public static function missingAllocationValue(string $keyLabel, string $unitLabel): self
    {
        return new self(sprintf(
            'Für den Verteilerschlüssel %s fehlt der Wert der Einheit %s. Bitte ergänzen Sie den Wert in '
            .'Schritt 8. Es wird kein Wert geschätzt.',
            $keyLabel,
            $unitLabel
        ));
    }

    public static function emptyAllocationKey(string $keyLabel): self
    {
        return new self(sprintf(
            'Für den Verteilerschlüssel %s ist kein einziger Wert erfasst. Bitte ergänzen Sie die Werte in '
            .'Schritt 8.',
            $keyLabel
        ));
    }

    public static function missingPrepayment(string $tenantLabel): self
    {
        return new self(sprintf(
            'Für das Mietverhältnis %s sind keine Vorauszahlungen erfasst. Schritt 7 ist ein Pflichtschritt. '
            .'Bitte erfassen Sie die Vorauszahlungen oder bestätigen Sie ausdrücklich die Annahme Ist gleich Soll.',
            $tenantLabel
        ));
    }

    public static function missingActualPrepayment(string $tenantLabel): self
    {
        return new self(sprintf(
            'Für das Mietverhältnis %s fehlen die tatsächlich geleisteten Vorauszahlungen. Bitte tragen Sie den '
            .'Betrag ein oder bestätigen Sie ausdrücklich die Annahme Ist gleich Soll. Es wird nichts geschätzt.',
            $tenantLabel
        ));
    }

    public static function unconfirmedSubstituteDistribution(string $unitLabel): self
    {
        return new self(sprintf(
            'Für die Einheit %s liegt bei Nutzerwechsel keine Zwischenablesung vor. Eine Ersatzverteilung ist '
            .'ausdrücklich zu bestätigen. Es wird nicht still geschätzt.',
            $unitLabel
        ));
    }

    public static function missingPersonSegments(string $keyLabel, string $tenantLabel): self
    {
        return new self(sprintf(
            'Für den Verteilerschlüssel %s fehlen die Personenangaben des Mietverhältnisses %s für den gesamten '
            .'Nutzungszeitraum. Bitte erfassen Sie die Belegungszeiträume in Schritt 5. Ohne Personenangabe '
            .'würde der Anteil stillschweigend auf die übrigen Mieter verschoben; das wird nicht getan.',
            $keyLabel,
            $tenantLabel
        ));
    }

    public static function personDaysWithVacancy(string $keyLabel, string $unitLabel): self
    {
        return new self(sprintf(
            'Für den Verteilerschlüssel %s ist die Einheit %s im Abrechnungszeitraum nicht durchgehend vermietet '
            .'(Leerstand oder Eigennutzung). Für einen nicht vermieteten Zeitraum liegt keine Personenangabe vor; '
            .'ohne sie ginge sein Anteil stillschweigend auf die übrigen Mieter über, eine Personenannahme wäre '
            .'eine Schätzung. Beides wird nicht getan. Bitte wählen Sie für die Kostenart in Schritt 8 einen '
            .'anderen Verteilerschlüssel.',
            $keyLabel,
            $unitLabel
        ));
    }

    public static function unconfirmedCostItem(string $costItemLabel): self
    {
        return new self(sprintf(
            'Die Kostenposition %s ist nur vorgeschlagen und noch nicht bestätigt. Bitte bestätigen oder verwerfen '
            .'Sie die Position in der Kostenprüfung. Eine unbestätigte Position wird nicht berechnet.',
            $costItemLabel
        ));
    }

    public static function directAssignmentExceedsAmount(string $keyLabel, string $assigned, string $amount): self
    {
        return new self(sprintf(
            'Die Direktzuordnung %s ordnet insgesamt %s EUR zu, die zugehörige Kostenposition beträgt aber nur '
            .'%s EUR. Bitte prüfen Sie die zugeordneten Beträge in Schritt 8 oder den Positionsbetrag in der '
            .'Kostenprüfung. Es wird nichts umverteilt.',
            $keyLabel,
            $assigned,
            $amount
        ));
    }

    public static function unknownDirectUnit(string $costItemLabel): self
    {
        return new self(sprintf(
            'Die Kostenposition %s ist einer Einheit direkt zugeordnet, die nicht zum Objekt dieses '
            .'Abrechnungslaufs gehört. Bitte korrigieren Sie die Zuordnung in der Kostenprüfung.',
            $costItemLabel
        ));
    }

    public static function noCostItems(): self
    {
        return new self(
            'Es ist keine bestätigte Kostenposition vorhanden. Bitte schließen Sie die Kostenprüfung ab.'
        );
    }

    public static function snapshotNotReproducible(string $snapshotId): self
    {
        return new self(sprintf(
            'Der gespeicherte Berechnungsstand %s kann nicht gelesen werden. Bitte berechnen Sie den '
            .'Abrechnungslauf erneut.',
            $snapshotId
        ));
    }
}
