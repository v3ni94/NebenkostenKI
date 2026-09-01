<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Engine\RuleEngine;
use App\Rules\Engine\RuleRegistry;
use App\Rules\Engine\Ruleset;
use App\Rules\Engine\RulesetCatalog;
use App\Rules\Engine\UnknownRulesetVersionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Versionierung der Regelstaende.
 *
 * Ein Regelstand buendelt die zu einem Stichtag gueltigen Regeln. Ein alter
 * Berechnungsstand bleibt mit seinem Regelstand reproduzierbar.
 */
final class RulesetVersioningTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function der_regelstand_eines_stichtags_enthaelt_nur_die_dann_gueltigen_regeln(): void
    {
        $ruleset = Ruleset::forDate($this->day('2021-01-01'));

        $this->assertSame('2020.1', $ruleset->version);
        $this->assertFalse($ruleset->has('HEIZKOSTEN_CO2_STATUS'));
        $this->assertTrue($ruleset->has('FRIST_ABRECHNUNGSZEITRAUM'));
    }

    #[Test]
    public function ein_spaeterer_regelstand_hat_eine_andere_zusammensetzung(): void
    {
        $frueher = Ruleset::forDate($this->day('2021-01-01'));
        $spaeter = Ruleset::forDate($this->day('2025-01-01'));

        $this->assertSame('2023.1', $spaeter->version);
        $this->assertNotSame($frueher->version, $spaeter->version);
        $this->assertTrue($spaeter->has('HEIZKOSTEN_CO2_STATUS'));
        $this->assertSame($frueher->count() + 1, $spaeter->count());
    }

    #[Test]
    public function der_regelstand_richtet_sich_nach_dem_abrechnungszeitraum(): void
    {
        $context = $this->context(billingPeriod: DatePeriodRange::calendarYear(2022));

        $this->assertSame('2020.1', Ruleset::forContext($context)->version);
    }

    #[Test]
    public function ein_alter_berechnungsstand_bleibt_mit_seinem_regelstand_reproduzierbar(): void
    {
        $context = $this->context(
            billingPeriod: DatePeriodRange::calendarYear(2022),
            heatingStatements: [
                new RuleHeatingStatement(
                    'heiz-1',
                    HeatingSupplyCase::EXTERN_ABGERECHNET,
                    DatePeriodRange::calendarYear(2022),
                    'Wärmemess Rothbach GmbH',
                    $this->euros('7200.00'),
                    ['nutzung-1' => $this->euros('7200.00')],
                    Co2ShareStatus::UNBEKANNT,
                ),
            ]
        );

        $engine = new RuleEngine;
        $alt = $engine->reproduce('2020.1', $context);
        $neu = $engine->reproduce('2023.1', $context);

        $this->assertSame('2020.1', $alt->rulesetVersion);
        $this->assertSame([], array_filter(
            $alt->results,
            static fn ($result): bool => $result->ruleCode === 'HEIZKOSTEN_CO2_STATUS'
        ));
        $this->assertNotSame([], array_filter(
            $neu->results,
            static fn ($result): bool => $result->ruleCode === 'HEIZKOSTEN_CO2_STATUS'
        ));
    }

    #[Test]
    public function eine_wiederholte_pruefung_mit_demselben_regelstand_liefert_dasselbe_ergebnis(): void
    {
        $context = $this->context(billingPeriod: DatePeriodRange::calendarYear(2022));
        $engine = new RuleEngine;

        $erst = $engine->reproduce('2020.1', $context);
        $zweit = $engine->reproduce('2020.1', $context);

        $this->assertSame(
            array_map(static fn ($result): string => $result->ruleCode.'|'.$result->severity->value, $erst->results),
            array_map(static fn ($result): string => $result->ruleCode.'|'.$result->severity->value, $zweit->results),
        );
    }

    #[Test]
    public function ein_unbekannter_regelstand_wird_abgelehnt(): void
    {
        $this->expectException(UnknownRulesetVersionException::class);

        Ruleset::fromVersion('1999.9');
    }

    #[Test]
    public function jeder_gueltigkeitsbeginn_hat_einen_eigenen_regelstand(): void
    {
        $generationDates = array_map(
            static fn ($generation): string => $generation->validFrom->format('Y-m-d'),
            RulesetCatalog::generations()
        );

        foreach (RuleRegistry::validityBoundaries() as $boundary) {
            $this->assertContains(
                $boundary,
                $generationDates,
                'Zu jedem Gültigkeitsbeginn einer Regel muss ein Regelstand bestehen.'
            );
        }
    }

    #[Test]
    public function die_versionen_des_katalogs_sind_eindeutig(): void
    {
        $versions = RulesetCatalog::versions();

        $this->assertSame($versions, array_values(array_unique($versions)));
    }
}
