<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCategoryChecksum;
use App\Rules\Context\RuleHausgeldChecksum;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Definitions\CategoryChecksumRule;
use App\Rules\Definitions\ExternalHeatingChecksumRule;
use App\Rules\Definitions\HausgeldShareChecksumRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Pruefsummen: Belegsumme gegen Kategoriensumme, Hausgeldanteile gegen
 * Gesamtsumme, externe Heizkosten gegen Gesamtbetrag.
 */
final class ChecksumRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function kategoriensumme_innerhalb_der_toleranz_ergibt_keinen_befund(): void
    {
        $context = $this->context(categoryChecksums: [
            new RuleCategoryChecksum('KAT-WASSER', 'Wasserversorgung', $this->euros('2400.00'), $this->euros('2400.50')),
        ]);

        $this->assertSame([], $this->evaluate(new CategoryChecksumRule, $context));
    }

    #[Test]
    public function kategoriensumme_ausserhalb_der_toleranz_blockiert(): void
    {
        $context = $this->context(categoryChecksums: [
            new RuleCategoryChecksum('KAT-WASSER', 'Wasserversorgung', $this->euros('2400.00'), $this->euros('2650.00')),
        ]);

        $findings = $this->evaluate(new CategoryChecksumRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('250,00 EUR', $findings[0]->description);
        $this->assertSame(25000, $findings[0]->context['differenceCent']);
    }

    #[Test]
    public function hausgeldanteile_ergeben_die_gesamtsumme(): void
    {
        $context = $this->context(hausgeldChecksums: [
            new RuleHausgeldChecksum('Gebäudereinigung', $this->euros('1800.00'), [
                'einheit-1' => $this->euros('900.00'),
                'einheit-2' => $this->euros('900.00'),
            ]),
        ]);

        $this->assertSame([], $this->evaluate(new HausgeldShareChecksumRule, $context));
    }

    #[Test]
    public function abweichende_hausgeldanteile_blockieren(): void
    {
        $context = $this->context(hausgeldChecksums: [
            new RuleHausgeldChecksum('Gebäudereinigung', $this->euros('1800.00'), [
                'einheit-1' => $this->euros('900.00'),
                'einheit-2' => $this->euros('700.00'),
            ]),
        ]);

        $findings = $this->evaluate(new HausgeldShareChecksumRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('1.600,00 EUR', $findings[0]->description);
    }

    #[Test]
    public function externe_heizkosten_innerhalb_der_toleranz_ergeben_keinen_befund(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Wärmemess Rothbach GmbH',
                $this->euros('7200.00'),
                [
                    'nutzung-1' => $this->euros('4000.00'),
                    'nutzung-2' => $this->euros('3200.00'),
                ],
                Co2ShareStatus::ENTHALTEN,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new ExternalHeatingChecksumRule, $context));
    }

    #[Test]
    public function externe_heizkosten_ausserhalb_der_toleranz_blockieren(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Wärmemess Rothbach GmbH',
                $this->euros('7200.00'),
                [
                    'nutzung-1' => $this->euros('4000.00'),
                    'nutzung-2' => $this->euros('2500.00'),
                ],
                Co2ShareStatus::ENTHALTEN,
            ),
        ]);

        $findings = $this->evaluate(new ExternalHeatingChecksumRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertSame('heiz-1', $findings[0]->entityId);
        $this->assertStringContainsString('Finalisierung', $findings[0]->description);
    }

    #[Test]
    public function externe_heizkosten_ohne_gesamtbetrag_blockieren(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-2',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Abrechnungsdienst Hochkamp GmbH',
            ),
        ]);

        $findings = $this->evaluate(new ExternalHeatingChecksumRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
    }
}
