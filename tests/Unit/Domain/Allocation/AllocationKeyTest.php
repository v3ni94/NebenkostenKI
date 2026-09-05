<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Allocation;

use App\Domain\Allocation\AllocationKeyScope;
use App\Domain\Allocation\AllocationKeyType;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\CoOwnershipShareKey;
use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Allocation\HeatedLivingAreaKey;
use App\Domain\Allocation\IndividualKey;
use App\Domain\Allocation\InvalidAllocationKeyException;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\PersonCountKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Alle Verteilerschlüsseltypen liefern Zähler, Nenner, exakten Anteil und
 * einen menschenlesbaren Erklärungstext für das PDF.
 */
final class AllocationKeyTest extends TestCase
{
    #[Test]
    public function wohnflaechenschluessel_liefert_anteil_und_erklaerungstext(): void
    {
        $key = new LivingAreaKey([
            'W-1' => '72.50',
            'W-2' => '95.00',
            'W-3' => '142.50',
        ]);

        $this->assertSame(AllocationKeyType::LIVING_AREA, $key->type());
        $this->assertSame(AllocationKeyScope::UNIT, $key->scope());
        $this->assertSame('310.00', (string) $key->denominator());
        $this->assertSame('Wohnfläche 72,50 m² von 310,00 m²', $key->explanationFor('W-1'));
        $this->assertSame('72,50', $key->formattedNumeratorFor('W-1'));
        $this->assertSame('310,00', $key->formattedDenominator());
        // 72,50 / 310,00 = 29/124 (exakter Bruch, keine Zwischenrundung)
        $this->assertSame('29/124', (string) $key->shareFor('W-1')->simplified());
    }

    #[Test]
    public function beheizte_wohnflaeche_ist_ein_eigener_schluessel(): void
    {
        $key = new HeatedLivingAreaKey(['W-1' => '68.00', 'W-2' => '92.00']);

        $this->assertSame(AllocationKeyType::HEATED_LIVING_AREA, $key->type());
        $this->assertSame('beheizte Wohnfläche 68,00 m² von 160,00 m²', $key->explanationFor('W-1'));
    }

    #[Test]
    public function miteigentumsanteile_nutzen_den_gesamtnenner_der_teilungserklaerung(): void
    {
        $key = CoOwnershipShareKey::withTotalShares(['W-12' => '187.50'], '1000.00');

        $this->assertSame('Miteigentumsanteil 187,50 von 1.000,00', $key->explanationFor('W-12'));
        $this->assertSame('3/16', (string) $key->shareFor('W-12')->simplified());
        $this->assertSame('187.50', (string) $key->numeratorSum());
        $this->assertTrue($key->shareFor('W-12')->isEqualTo('0.1875'));
    }

    #[Test]
    public function personenschluessel_zeigt_ganze_personen(): void
    {
        $key = new PersonCountKey(['W-1' => 2, 'W-2' => 3, 'W-3' => 1]);

        $this->assertSame('Personen 2 von 6', $key->explanationFor('W-1'));
        $this->assertSame('1/3', (string) $key->shareFor('W-1')->simplified());
    }

    #[Test]
    public function einheitenschluessel_verteilt_gleichmaessig(): void
    {
        $key = UnitCountKey::forUnits(['W-1', 'W-2', 'W-3', 'W-4', 'W-5', 'W-6']);

        $this->assertSame('6', (string) $key->denominator());
        $this->assertSame('1/6', (string) $key->shareFor('W-4')->simplified());
        $this->assertSame('Einheiten 1 von 6', $key->explanationFor('W-4'));
    }

    #[Test]
    public function personentage_sind_personenanzahl_mal_gueltigkeitstage(): void
    {
        $billingPeriod = DatePeriodRange::calendarYear(2025);

        $key = PersonDaysKey::fromSegments([
            new PersonDaysSegment('mv-2', 3, DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
            new PersonDaysSegment('mv-3', 1, DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
            new PersonDaysSegment('mv-1', 2, DatePeriodRange::calendarYear(2025)),
        ], $billingPeriod);

        // 3 × 181 = 543, 1 × 184 = 184, 2 × 365 = 730 → Nenner 1.457
        $this->assertSame('543', (string) $key->numeratorFor('mv-2'));
        $this->assertSame('184', (string) $key->numeratorFor('mv-3'));
        $this->assertSame('730', (string) $key->numeratorFor('mv-1'));
        $this->assertSame('1457', (string) $key->denominator());
        $this->assertSame(AllocationKeyScope::OCCUPANCY, $key->scope());
        $this->assertSame(
            'Personentage 543 von 1.457 (3 Personen × 181 Tage)',
            $key->explanationFor('mv-2')
        );
    }

    #[Test]
    public function personentage_beruecksichtigen_nur_tage_im_abrechnungszeitraum(): void
    {
        $key = PersonDaysKey::fromSegments([
            new PersonDaysSegment('mv-1', 2, DatePeriodRange::fromIso('2024-10-01', '2025-01-31')),
            new PersonDaysSegment('mv-2', 2, DatePeriodRange::fromIso('2025-02-01', '2025-12-31')),
        ], DatePeriodRange::calendarYear(2025));

        // Nur Januar 2025 zählt: 2 × 31 = 62 Personentage
        $this->assertSame('62', (string) $key->numeratorFor('mv-1'));
        $this->assertSame('668', (string) $key->numeratorFor('mv-2'));
    }

    #[Test]
    public function personentage_bei_wechselnder_personenanzahl_werden_summiert(): void
    {
        $key = PersonDaysKey::fromSegments([
            new PersonDaysSegment('mv-1', 3, DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
            new PersonDaysSegment('mv-1', 2, DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
        ], DatePeriodRange::calendarYear(2025));

        // 3 × 181 + 2 × 184 = 543 + 368 = 911
        $this->assertSame('911', (string) $key->numeratorFor('mv-1'));
        $this->assertSame(
            'Personentage 911 von 911 (3 Personen × 181 Tage + 2 Personen × 184 Tage)',
            $key->explanationFor('mv-1')
        );
    }

    #[Test]
    public function verbrauchsschluessel_nutzt_drei_dezimalstellen_und_masseinheit(): void
    {
        $key = ConsumptionKey::create([
            'mv-1' => '82.000',
            'mv-2' => '61.000',
        ], 'm³');

        $this->assertSame(AllocationKeyScope::OCCUPANCY, $key->scope());
        $this->assertSame('Verbrauch 82,000 m³ von 143,000 m³', $key->explanationFor('mv-1'));
        $this->assertSame('m³', $key->measurementUnit());
        $this->assertFalse($key->usesSubstituteDistributionFor('mv-1'));
    }

    #[Test]
    public function bestaetigte_ersatzverteilung_wird_im_erklaerungstext_gekennzeichnet(): void
    {
        $key = ConsumptionKey::create(
            ['mv-1' => '40.000', 'mv-2' => '60.000'],
            'kWh',
            ['mv-1', 'mv-2']
        );

        $this->assertStringContainsString(
            'bestätigte Ersatzverteilung ohne Zwischenablesung',
            $key->explanationFor('mv-1')
        );
        $this->assertTrue($key->usesSubstituteDistributionFor('mv-2'));
        $this->assertSame(['mv-1', 'mv-2'], $key->substituteParticipants());
    }

    #[Test]
    public function direktzuordnung_bildet_betraege_als_gewichte_ab(): void
    {
        $key = DirectAssignmentKey::fromAmounts([
            'mv-1' => Money::fromEuros('720.00'),
            'mv-2' => Money::fromEuros('610.00'),
        ]);

        $this->assertSame(AllocationKeyType::DIRECT_ASSIGNMENT, $key->type());
        $this->assertSame(AllocationKeyScope::OCCUPANCY, $key->scope());
        $this->assertSame('Direktzuordnung 720,00 EUR von 1.330,00 EUR', $key->explanationFor('mv-1'));
        $this->assertSame('720,00', $key->formattedNumeratorFor('mv-1'));
        $this->assertSame('1.330,00', $key->formattedDenominator());
        $this->assertTrue($key->amountFor('mv-2')->equals(Money::fromEuros('610.00')));
        $this->assertTrue($key->totalAmount()->equals(Money::fromEuros('1330.00')));
    }

    #[Test]
    public function individuelle_schluessel_eins_bis_fuenf_sind_moeglich(): void
    {
        foreach ([1, 2, 3, 4, 5] as $index) {
            $key = new IndividualKey($index, ['W-1' => '1.00', 'W-2' => '3.00']);

            $this->assertSame('INDIVIDUAL_'.$index, $key->type()->value);
            $this->assertSame('1/4', (string) $key->shareFor('W-1')->simplified());
        }
    }

    #[Test]
    public function individueller_schluessel_kann_eigene_bezeichnung_und_einheit_fuehren(): void
    {
        $key = new IndividualKey(
            2,
            ['W-1' => '12.00', 'W-2' => '28.00'],
            null,
            'Nutzfläche Gewerbe',
            'm²'
        );

        $this->assertSame('Nutzfläche Gewerbe 12,00 m² von 40,00 m²', $key->explanationFor('W-1'));
    }

    #[Test]
    public function individueller_schluessel_ausserhalb_eins_bis_fuenf_ist_unzulaessig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IndividualKey(6, ['W-1' => '1.00']);
    }

    #[Test]
    public function nenner_null_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);
        $this->expectExceptionMessage('Nenner null');

        new LivingAreaKey(['W-1' => '0.00', 'W-2' => '0.00']);
    }

    #[Test]
    public function negativer_nenner_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);

        CoOwnershipShareKey::withTotalShares(['W-1' => '100.00'], '-1000.00');
    }

    #[Test]
    public function negativer_zaehler_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);

        new LivingAreaKey(['W-1' => '-72.50', 'W-2' => '95.00']);
    }

    #[Test]
    public function leerer_schluessel_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);

        new LivingAreaKey([]);
    }

    #[Test]
    public function zaehlersumme_darf_den_nenner_nicht_uebersteigen(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);

        CoOwnershipShareKey::withTotalShares(['W-1' => '600.00', 'W-2' => '600.00'], '1000.00');
    }

    #[Test]
    public function unbekannter_beteiligter_hat_zaehler_null(): void
    {
        $key = new LivingAreaKey(['W-1' => '72.50', 'W-2' => '95.00']);

        $this->assertFalse($key->hasParticipant('W-9'));
        $this->assertSame('0', (string) $key->numeratorFor('W-9'));
        $this->assertTrue($key->shareFor('W-9')->isZero());
        $this->assertSame(['W-1', 'W-2'], $key->participantKeys());
    }
}
