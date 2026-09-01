<?php

declare(strict_types=1);

namespace Tests\Unit\Calculation;

use App\Application\Calculation\SnapshotSerializer;
use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\TestCase;

/**
 * Normalisierung und verlustfreier Wiederaufbau der Eingabe.
 *
 * Reiner Unittest ohne Datenbank und ohne Laravel-Container.
 */
final class SnapshotSerializerTest extends TestCase
{
    public function test_dezimalwerte_bleiben_zeichenketten(): void
    {
        $payload = (new SnapshotSerializer)->input($this->eingabe());

        self::assertIsArray($payload['units']);
        self::assertSame('100.0000', $payload['units'][0]['livingAreaSqm']);
        self::assertIsString($payload['units'][0]['livingAreaSqm']);
        self::assertIsString($payload['allocationKeys']['SCHLUESSEL-1']['denominator']);
        self::assertIsString($payload['costItems'][0]['totalAmountCent']);
    }

    public function test_wiederaufbau_ist_verlustfrei(): void
    {
        $serializer = new SnapshotSerializer;
        $payload = $serializer->input($this->eingabe());

        self::assertSame(
            $serializer->canonical($payload),
            $serializer->canonical($serializer->input($serializer->hydrate($payload)))
        );
    }

    public function test_wiederaufbau_liefert_dasselbe_ergebnis(): void
    {
        $serializer = new SnapshotSerializer;
        $rechner = new StatementCalculator;

        $original = $serializer->result($rechner->calculate($this->eingabe()));
        $payload = $serializer->input($this->eingabe());
        $erneut = $serializer->result($rechner->calculate($serializer->hydrate($payload)));

        self::assertSame($original, $erneut);
    }

    public function test_personentageschluessel_behaelt_den_rechenweg(): void
    {
        $serializer = new SnapshotSerializer;
        $eingabe = $this->eingabeMitPersonentagen();
        $payload = $serializer->input($eingabe);

        $wiederaufgebaut = $serializer->hydrate($payload)->allocationKeys['SCHLUESSEL-1'];

        self::assertInstanceOf(PersonDaysKey::class, $wiederaufgebaut);
        self::assertStringContainsString(
            'Personen × 365 Tage',
            $wiederaufgebaut->explanationFor('M-1')
        );
    }

    public function test_verbrauchsschluessel_behaelt_masseinheit_und_ersatzverteilung(): void
    {
        $serializer = new SnapshotSerializer;

        $key = ConsumptionKey::create(['M-1' => '10.000', 'M-2' => '30.000'], 'm3', ['M-1']);
        $payload = $serializer->input($this->eingabe($key));

        $wiederaufgebaut = $serializer->hydrate($payload)->allocationKeys['SCHLUESSEL-1'];

        self::assertInstanceOf(ConsumptionKey::class, $wiederaufgebaut);
        self::assertSame('m3', $wiederaufgebaut->measurementUnit());
        self::assertSame(['M-1'], $wiederaufgebaut->substituteParticipants());
        self::assertTrue($wiederaufgebaut->usesSubstituteDistributionFor('M-1'));
    }

    public function test_der_hash_beruecksichtigt_die_regelversion(): void
    {
        $serializer = new SnapshotSerializer;
        $eingabe = $serializer->input($this->eingabe());
        $ergebnis = $serializer->result((new StatementCalculator)->calculate($this->eingabe()));

        $ersterHash = $serializer->hash($eingabe, $ergebnis, '1.0.0', '2020.1');
        $zweiterHash = $serializer->hash($eingabe, $ergebnis, '1.0.0', '2023.1');

        self::assertNotSame($ersterHash, $zweiterHash);
        self::assertSame(64, strlen($ersterHash));
    }

    public function test_die_kanonische_form_ist_unabhaengig_von_der_schluesselreihenfolge(): void
    {
        $serializer = new SnapshotSerializer;

        self::assertSame(
            $serializer->canonical(['a' => 1, 'b' => 2]),
            $serializer->canonical(['b' => 2, 'a' => 1])
        );
        self::assertNotSame(
            $serializer->canonical([1, 2]),
            $serializer->canonical([2, 1])
        );
    }

    public function test_das_ergebnis_enthaelt_zeilen_und_pruefergebnisse(): void
    {
        $serializer = new SnapshotSerializer;
        $payload = $serializer->result((new StatementCalculator)->calculate($this->eingabe()));

        self::assertSame(2, $payload['statementCount']);
        self::assertIsArray($payload['statements']);
        self::assertIsArray($payload['findings']);
        self::assertArrayHasKey('lines', $payload['statements'][0]);
        self::assertArrayHasKey('calculationExplanation', $payload['statements'][0]['lines'][0]);
    }

    private function eingabe(?object $key = null): StatementCalculationInput
    {
        $period = DatePeriodRange::calendarYear(2025);

        return new StatementCalculationInput(
            $period,
            [
                new UnitInput('E-1', 'Wohnung A', '100.0000', '100.0000', '600.000000', [1 => '2.0000']),
                new UnitInput('E-2', 'Wohnung B', '50.0000', '50.0000', '400.000000'),
            ],
            [
                OccupancyInput::tenancy('M-1', 'E-1', $period, 'Mietpartei A', 'Sonnenweg 4, 40789 Musterstadt'),
                OccupancyInput::tenancy('M-2', 'E-2', $period, 'Mietpartei B'),
            ],
            [
                new CostItemInput(
                    'K-1',
                    'reinigung',
                    'Gebäudereinigung',
                    Money::fromCents(120000),
                    'SCHLUESSEL-1',
                ),
            ],
            [
                'SCHLUESSEL-1' => $key instanceof AllocationKey
                    ? $key
                    : new LivingAreaKey(['E-1' => '100.0000', 'E-2' => '50.0000']),
            ],
            [
                PrepaymentInput::actual('M-1', Money::fromCents(288000), Money::fromCents(288000), 'Zahlungsübersicht'),
                PrepaymentInput::assumedFromTarget('M-2', Money::fromCents(144000), 'Mietvertrag'),
            ],
            'Beispielobjekt Sonnenweg 4',
        );
    }

    private function eingabeMitPersonentagen(): StatementCalculationInput
    {
        $period = DatePeriodRange::calendarYear(2025);
        $eingabe = $this->eingabe();

        $occupancies = [
            $eingabe->occupancies[0]->withPersonSegments([new PersonDaysSegment('M-1', 3, $period)]),
            $eingabe->occupancies[1]->withPersonSegments([new PersonDaysSegment('M-2', 2, $period)]),
        ];

        return new StatementCalculationInput(
            $period,
            $eingabe->units,
            $occupancies,
            $eingabe->costItems,
            [
                'SCHLUESSEL-1' => PersonDaysKey::fromSegments(
                    [
                        new PersonDaysSegment('M-1', 3, $period),
                        new PersonDaysSegment('M-2', 2, $period),
                    ],
                    $period
                ),
            ],
            $eingabe->prepayments,
            $eingabe->propertyLabel,
        );
    }
}
