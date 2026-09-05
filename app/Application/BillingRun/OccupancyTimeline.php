<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\PeriodCoverage;
use App\Enums\TenancyKind;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;
use Illuminate\Support\Carbon;

/**
 * Zeitachse einer Einheit aus Mietverhaeltnissen und Leerstaenden.
 *
 * Vorgabe des Masterprompts, Schritt 5: keine Ueberschneidungen, lueckenlose
 * Belegung oder ausdruecklicher Leerstand ueber den gesamten
 * Abrechnungszeitraum, Zustellanschrift bei ausgezogenem Mieter.
 *
 * Die taggenaue Zeitlogik liegt vollstaendig in der Domainschicht
 * (App\Domain\Period). Diese Klasse liest die Modelle, uebersetzt sie in
 * Zeitraeume und formuliert die Ergebnisse als deutsche Hinweistexte fuer die
 * Oberflaeche. Sie rechnet selbst nicht.
 *
 * Ein laufendes Mietverhaeltnis ohne Auszugsdatum wird fuer die Pruefung bis
 * zum Ende des Rahmenzeitraums gefuehrt. Das ist keine Annahme ueber die
 * Zukunft, sondern die Auswertung des Rahmens.
 */
class OccupancyTimeline
{
    /**
     * Prueft, ob sich ein geplanter Zeitraum mit bestehenden Eintraegen der
     * Einheit ueberschneidet.
     *
     * @param  string|null  $exceptTenancyId  eigener Datensatz beim Bearbeiten
     */
    public function overlapsExisting(
        Unit $unit,
        string $startsOn,
        ?string $endsOn,
        ?string $exceptTenancyId = null,
    ): bool {
        $neu = $this->range($startsOn, $endsOn ?? '9999-12-31');

        if (! $neu instanceof DatePeriodRange) {
            return false;
        }

        foreach ($this->tenancyRanges($unit, $exceptTenancyId) as $bestehend) {
            if ($neu->overlaps($bestehend)) {
                return true;
            }
        }

        foreach ($this->vacancyRanges($unit) as $bestehend) {
            if ($neu->overlaps($bestehend)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prueft, ob sich ein geplanter Leerstand mit bestehenden Eintraegen
     * ueberschneidet.
     */
    public function vacancyOverlapsExisting(
        Unit $unit,
        string $startsOn,
        string $endsOn,
        ?string $exceptVacancyId = null,
    ): bool {
        $neu = $this->range($startsOn, $endsOn);

        if (! $neu instanceof DatePeriodRange) {
            return false;
        }

        foreach ($this->tenancyRanges($unit, null) as $bestehend) {
            if ($neu->overlaps($bestehend)) {
                return true;
            }
        }

        foreach ($this->vacancyRanges($unit, $exceptVacancyId) as $bestehend) {
            if ($neu->overlaps($bestehend)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nicht abgedeckte Teilzeitraeume innerhalb des Rahmens. Leerstand gilt als
     * Abdeckung.
     *
     * @return list<DatePeriodRange>
     */
    public function gaps(Unit $unit, DatePeriodRange $frame): array
    {
        $zeitraeume = array_merge(
            $this->tenancyRanges($unit, null),
            $this->vacancyRanges($unit)
        );

        return PeriodCoverage::gapsWithin($frame, $zeitraeume);
    }

    public function isFullyCovered(Unit $unit, DatePeriodRange $frame): bool
    {
        return $this->gaps($unit, $frame) === [];
    }

    /**
     * Verstaendliche Hinweistexte zur Zeitachse einer Einheit.
     *
     * @return list<array{art: string, text: string}>
     */
    public function findings(Unit $unit, ?DatePeriodRange $frame = null): array
    {
        $befunde = [];

        if ($frame instanceof DatePeriodRange) {
            foreach ($this->gaps($unit, $frame) as $luecke) {
                $befunde[] = [
                    'art' => PortalStatusCategory::FEHLT_NOCH,
                    // Die Einheit steht im Satz, damit gleichlautende Befunde mehrerer
                    // Einheiten auf Uebersicht und Objektliste unterscheidbar bleiben.
                    'text' => sprintf(
                        '%s: Für den Zeitraum vom %s bis %s ist weder ein Mietverhältnis noch ein Leerstand erfasst.',
                        (string) $unit->getAttribute('label'),
                        $this->deutschesDatum($luecke->startIso()),
                        $this->deutschesDatum($luecke->endIso())
                    ),
                ];
            }
        }

        foreach ($this->tenancies($unit) as $tenancy) {
            $name = $tenancy->getAttribute('tenant_display_name');
            $name = is_string($name) && $name !== '' ? $name : 'Mietverhältnis';

            if ($tenancy->hasMovedOut() && ! $this->hasDeliveryAddress($tenancy)) {
                $befunde[] = [
                    'art' => PortalStatusCategory::BLOCKIERT,
                    'text' => sprintf(
                        'Für %s fehlt die Zustellanschrift. Bei beendeten Mietverhältnissen ist sie erforderlich.',
                        $name
                    ),
                ];
            }

            if ($tenancy->getAttribute('kind') === TenancyKind::GEWERBE) {
                $befunde[] = [
                    'art' => PortalStatusCategory::BITTE_PRUEFEN,
                    'text' => sprintf(
                        'Für %s ist Gewerbe hinterlegt. Gewerbliche Mietverhältnisse werden nicht automatisch '
                        .'finalisiert und sind gesondert zu prüfen.',
                        $name
                    ),
                ];
            }
        }

        return $befunde;
    }

    public function hasDeliveryAddress(Tenancy $tenancy): bool
    {
        foreach (['delivery_address_line', 'delivery_postal_code', 'delivery_city'] as $feld) {
            $wert = $tenancy->getAttribute($feld);

            if (! is_string($wert) || trim($wert) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Tenancy>
     */
    private function tenancies(Unit $unit): array
    {
        /** @var list<Tenancy> $eintraege */
        $eintraege = $unit->tenancies()->orderBy('starts_on')->get()->all();

        return $eintraege;
    }

    /**
     * @return list<DatePeriodRange>
     */
    private function tenancyRanges(Unit $unit, ?string $exceptTenancyId): array
    {
        $ranges = [];

        foreach ($this->tenancies($unit) as $tenancy) {
            if ($exceptTenancyId !== null && $tenancy->getKey() === $exceptTenancyId) {
                continue;
            }

            $range = $this->range(
                $this->isoDatum($tenancy->getAttribute('starts_on')),
                $this->isoDatum($tenancy->getAttribute('ends_on')) ?? '9999-12-31'
            );

            if ($range instanceof DatePeriodRange) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * @return list<DatePeriodRange>
     */
    private function vacancyRanges(Unit $unit, ?string $exceptVacancyId = null): array
    {
        $ranges = [];

        /** @var list<VacancyPeriod> $leerstaende */
        $leerstaende = $unit->vacancyPeriods()->orderBy('starts_on')->get()->all();

        foreach ($leerstaende as $leerstand) {
            if ($exceptVacancyId !== null && $leerstand->getKey() === $exceptVacancyId) {
                continue;
            }

            $range = $this->range(
                $this->isoDatum($leerstand->getAttribute('starts_on')),
                $this->isoDatum($leerstand->getAttribute('ends_on'))
            );

            if ($range instanceof DatePeriodRange) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    private function range(?string $start, ?string $end): ?DatePeriodRange
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($end < $start) {
            return null;
        }

        return DatePeriodRange::fromIso($start, $end);
    }

    private function isoDatum(mixed $wert): ?string
    {
        if ($wert instanceof Carbon) {
            return $wert->toDateString();
        }

        if (is_string($wert) && $wert !== '') {
            return substr($wert, 0, 10);
        }

        return null;
    }

    private function deutschesDatum(string $iso): string
    {
        return Carbon::parse($iso)->format('d.m.Y');
    }
}
