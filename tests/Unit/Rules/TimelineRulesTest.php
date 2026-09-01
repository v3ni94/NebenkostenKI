<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RulePrepayment;
use App\Rules\Context\RuleTenancy;
use App\Rules\Context\RuleUnit;
use App\Rules\Definitions\MissingDeliveryAddressRule;
use App\Rules\Definitions\PrepaymentOutsideTenancyRule;
use App\Rules\Definitions\TenancyCoverageGapRule;
use App\Rules\Definitions\TenancyOverlapRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Zeitachse: Vorauszahlungen, Ueberschneidung, Luecke, Zustellanschrift.
 */
final class TimelineRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function vorauszahlung_im_mietzeitraum_ergibt_keinen_befund(): void
    {
        $context = $this->context(
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Frau Cordula Wittkamp', DatePeriodRange::calendarYear(2025)),
            ],
            prepayments: [
                new RulePrepayment('vz-1', 'miet-1', DatePeriodRange::calendarYear(2025), $this->euros('1800.00')),
            ]
        );

        $this->assertSame([], $this->evaluate(new PrepaymentOutsideTenancyRule, $context));
    }

    #[Test]
    public function vorauszahlung_ausserhalb_des_mietzeitraums_ergibt_eine_warnung(): void
    {
        $context = $this->context(
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Frau Cordula Wittkamp', DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
            ],
            prepayments: [
                new RulePrepayment('vz-1', 'miet-1', DatePeriodRange::calendarYear(2025), $this->euros('1800.00')),
            ]
        );

        $findings = $this->evaluate(new PrepaymentOutsideTenancyRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('01.01.2025 bis 30.06.2025', $findings[0]->description);
    }

    #[Test]
    public function vorauszahlung_ohne_mietverhaeltnis_ergibt_eine_warnung(): void
    {
        $context = $this->context(prepayments: [
            new RulePrepayment('vz-1', 'miet-unbekannt', DatePeriodRange::calendarYear(2025), $this->euros('1800.00')),
        ]);

        $findings = $this->evaluate(new PrepaymentOutsideTenancyRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('keinem Mietverhältnis', $findings[0]->description);
    }

    #[Test]
    public function lueckenloser_mieterwechsel_ergibt_keine_ueberschneidung(): void
    {
        $context = $this->context(
            units: [new RuleUnit('einheit-1', 'Wohnung 1', '75.00')],
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Frau Cordula Wittkamp', DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
                new RuleTenancy('miet-2', 'einheit-1', 'Herr Detlef Karstaedt', DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
            ]
        );

        $this->assertSame([], $this->evaluate(new TenancyOverlapRule, $context));
        $this->assertSame([], $this->evaluate(new TenancyCoverageGapRule, $context));
    }

    #[Test]
    public function ueberschneidung_der_mietzeitraeume_blockiert(): void
    {
        $context = $this->context(
            units: [new RuleUnit('einheit-1', 'Wohnung 1', '75.00')],
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Frau Cordula Wittkamp', DatePeriodRange::fromIso('2025-01-01', '2025-07-31')),
                new RuleTenancy('miet-2', 'einheit-1', 'Herr Detlef Karstaedt', DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
            ]
        );

        $findings = $this->evaluate(new TenancyOverlapRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertSame(31, $findings[0]->context['overlapDays']);
    }

    #[Test]
    public function luecke_in_der_abdeckung_ergibt_einen_hinweis_auf_leerstand(): void
    {
        $context = $this->context(
            units: [new RuleUnit('einheit-1', 'Wohnung 1', '75.00')],
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Frau Cordula Wittkamp', DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
                new RuleTenancy('miet-2', 'einheit-1', 'Herr Detlef Karstaedt', DatePeriodRange::fromIso('2025-08-01', '2025-12-31')),
            ]
        );

        $findings = $this->evaluate(new TenancyCoverageGapRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertSame(31, $findings[0]->context['gapDays']);
        $this->assertStringContainsString('Leerstand', $findings[0]->description);
    }

    #[Test]
    public function ausgezogener_mieter_mit_anschrift_ergibt_keinen_befund(): void
    {
        $context = $this->context(tenancies: [
            new RuleTenancy(
                'miet-1',
                'einheit-1',
                'Frau Cordula Wittkamp',
                DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
                hasMovedOut: true,
                hasDeliveryAddress: true,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new MissingDeliveryAddressRule, $context));
    }

    #[Test]
    public function ausgezogener_mieter_ohne_anschrift_blockiert(): void
    {
        $context = $this->context(tenancies: [
            new RuleTenancy(
                'miet-1',
                'einheit-1',
                'Frau Cordula Wittkamp',
                DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
                hasMovedOut: true,
                hasDeliveryAddress: false,
            ),
        ]);

        $findings = $this->evaluate(new MissingDeliveryAddressRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('30.06.2025', $findings[0]->description);
    }
}
