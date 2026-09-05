<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Weg;

use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Money\Money;

/**
 * Übernahme der umlagefähigen Anteile einer WEG-Einzelabrechnung
 * (Pflichtenheft Abschnitt 7).
 *
 * Übernommen wird ausschließlich der auf die konkrete Eigentumseinheit
 * entfallende Anteil je Kostenart. Verbindlich ausgeschlossen werden nach
 * Abschnitt 7.2:
 *
 * - Hausgeldvorauszahlungen,
 * - Abrechnungsspitze, Nachzahlung oder Guthaben gegenüber der WEG,
 * - Zuführung zur Erhaltungsrücklage,
 * - Entnahme aus der Rücklage ohne Prüfung der zugrunde liegenden Kostenart,
 * - Verwalterkosten,
 * - Bank- und Finanzierungskosten,
 * - Instandhaltung, Instandsetzung und Reparaturen,
 * - Rechts- und Prozesskosten,
 * - nicht näher bezeichnete Sammelpositionen.
 *
 * Die Kennzeichnung des Verwalters als "umlagefähig" ist ein Vorschlag und
 * keine automatische Rechtsfreigabe.
 *
 * Heizkosten: Liegt eine externe Heizkostenabrechnung vor, dient die
 * WEG-Summenposition nur als Vergleichssumme und wird nicht zusätzlich
 * angesetzt (Abschnitt 7.4, Doppelzählung).
 *
 * Unzureichende Unterlagen: Fehlt die Kostenaufschlüsselung, wird ein
 * BLOCKER erzeugt (Abschnitt 7.5); es entsteht keine scheinbar vollständige
 * Abrechnung.
 *
 * Die erzeugten Kostenpositionen verweisen über allocationKeyRef auf einen
 * Verteilerschlüssel, den die Anwendungsschicht bereitstellt. Für eine
 * Eigentumswohnung ist das ein Schlüssel der Bezugsebene UNIT, der die
 * Einheit mit 100 Prozent führt (z. B. UnitCountKey::forUnits([$unitKey])),
 * damit ein Mieterwechsel taggenau berücksichtigt wird.
 */
final class HausgeldCostExtractor
{
    public function extract(
        HausgeldStatementInput $statement,
        string $allocationKeyRef,
        bool $externalHeatingStatementPresent = false,
    ): HausgeldExtractionResult {
        $accepted = [];
        $excluded = [];
        $findings = [];
        $acceptedTotal = Money::zero();
        $excludedTotal = Money::zero();

        if (! $statement->hasCostBreakdown()) {
            $findings[] = CheckFinding::blocker(
                CheckCode::WEG_INSUFFICIENT_BREAKDOWN,
                'Die WEG-Abrechnung enthält keine Aufschlüsselung nach Kostenarten. Aus dem monatlichen Hausgeld '
                .'oder der Abrechnungsspitze allein darf keine Betriebskostenabrechnung erzeugt werden. Bitte die '
                .'Einzelabrechnung beziehungsweise Kostenaufstellung anfordern.',
                ['unitKey' => $statement->unitKey]
            );
        }

        foreach ($statement->positions as $position) {
            if ($position->kind->isExcludedByRule()) {
                $excluded[] = new ExcludedHausgeldPosition(
                    $position->positionKey,
                    $position->label,
                    $position->unitShare,
                    $position->kind,
                    $position->kind->exclusionReason()
                );
                $excludedTotal = $excludedTotal->plus($position->unitShare);

                $findings[] = CheckFinding::info(
                    CheckCode::WEG_POSITION_EXCLUDED,
                    sprintf(
                        'Die Position "%s" (%s) ist als %s eingeordnet und wird nicht als Mietnebenkosten '
                        .'übernommen. %s',
                        $position->label,
                        $position->unitShare->format(),
                        $position->kind->label(),
                        $position->kind->exclusionReason()
                    ),
                    ['positionKey' => $position->positionKey, 'kind' => $position->kind->value]
                );

                if ($position->kind === HausgeldPositionKind::UNLABELLED_COLLECTIVE_POSITION) {
                    $findings[] = CheckFinding::warning(
                        CheckCode::WEG_UNLABELLED_POSITION,
                        sprintf(
                            'Die Sammelposition "%s" (%s) ist nicht aufgeschlüsselt. Eine Übernahme ist erst nach '
                            .'Vorlage der Einzelkosten möglich.',
                            $position->label,
                            $position->unitShare->format()
                        ),
                        ['positionKey' => $position->positionKey]
                    );
                }

                if ($position->declaredAllocable === true) {
                    $findings[] = CheckFinding::warning(
                        CheckCode::WEG_POSITION_EXCLUDED,
                        sprintf(
                            'Die Position "%s" ist in der WEG-Abrechnung als umlagefähig gekennzeichnet, gehört '
                            .'aber zu %s. Die Kennzeichnung des Verwalters ist ein Vorschlag und keine '
                            .'Rechtsfreigabe; die Position bleibt ausgeschlossen.',
                            $position->label,
                            $position->kind->label()
                        ),
                        ['positionKey' => $position->positionKey]
                    );
                }

                continue;
            }

            if ($position->kind === HausgeldPositionKind::HEATING_COST && $externalHeatingStatementPresent) {
                $excluded[] = new ExcludedHausgeldPosition(
                    $position->positionKey,
                    $position->label,
                    $position->unitShare,
                    $position->kind,
                    'Es liegt eine externe Heizkostenabrechnung vor. Die WEG-Summenposition dient nur als '
                    .'Vergleichssumme und wird nicht zusätzlich angesetzt.'
                );
                $excludedTotal = $excludedTotal->plus($position->unitShare);

                $findings[] = CheckFinding::info(
                    CheckCode::WEG_POSITION_EXCLUDED,
                    sprintf(
                        'Die Heizkostenposition "%s" (%s) aus der WEG-Abrechnung wird nicht angesetzt, weil eine '
                        .'externe Heizkostenabrechnung vorliegt. Sie dient als Vergleichssumme.',
                        $position->label,
                        $position->unitShare->format()
                    ),
                    ['positionKey' => $position->positionKey]
                );

                continue;
            }

            $accepted[] = new CostItemInput(
                sprintf('weg-%s', $position->positionKey),
                $position->categoryKey,
                $position->label,
                $position->unitShare,
                $allocationKeyRef,
                $position->kind->allocabilityStatus(),
                $statement->period,
                null,
                $position->taxBenefitCategory,
                $position->laborShare,
                $position->laborShareDisclosed,
                sprintf('WEG-Einzelabrechnung %s', $statement->wegLabel !== '' ? $statement->wegLabel : $statement->unitKey)
            );
            $acceptedTotal = $acceptedTotal->plus($position->unitShare);
        }

        if ($statement->totalUnitShare instanceof Money) {
            $checksum = $acceptedTotal->plus($excludedTotal);

            $findings[] = $checksum->equals($statement->totalUnitShare)
                ? CheckFinding::passed(
                    CheckCode::WEG_UNIT_SHARE_CHECKSUM,
                    sprintf(
                        'Die Einzelpositionen der WEG-Abrechnung ergeben %s und stimmen mit dem ausgewiesenen '
                        .'Gesamtanteil der Einheit überein.',
                        $checksum->format()
                    )
                )
                : CheckFinding::warning(
                    CheckCode::WEG_UNIT_SHARE_CHECKSUM,
                    sprintf(
                        'Die Einzelpositionen der WEG-Abrechnung ergeben %s, ausgewiesen ist ein Gesamtanteil von '
                        .'%s. Abweichung: %s.',
                        $checksum->format(),
                        $statement->totalUnitShare->format(),
                        $checksum->minus($statement->totalUnitShare)->format()
                    ),
                    ['differenceCent' => $checksum->minus($statement->totalUnitShare)->cents]
                );
        }

        return new HausgeldExtractionResult(
            $statement->unitKey,
            $accepted,
            $excluded,
            $findings,
            $acceptedTotal,
            $excludedTotal,
            $statement->hasCostBreakdown()
        );
    }
}
