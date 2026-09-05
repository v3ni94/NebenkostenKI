<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleEnvironment;
use App\Rules\Context\RuleTenancy;
use App\Rules\Definitions\MalwareScannerDisabledRule;
use App\Rules\Definitions\NotApportionableCostRule;
use App\Rules\Definitions\OtherOperatingCostsRule;
use App\Rules\Definitions\Paragraph35aLaborShareRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Umlagebewertung, sonstige Betriebskosten, Lohnanteil und Adminhinweis.
 */
final class ApportionmentRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function ausgeschlossene_nicht_umlagefaehige_kosten_ergeben_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Verwaltervergütung',
                'KAT-VERWALTUNG',
                'Verwaltungskosten',
                $this->euros('600.00'),
                apportionmentStatus: ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                excludedFromApportionment: true,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new NotApportionableCostRule, $context));
    }

    #[Test]
    public function nicht_umlagefaehige_kosten_in_der_umlage_ergeben_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Verwaltervergütung',
                'KAT-VERWALTUNG',
                'Verwaltungskosten',
                $this->euros('600.00'),
                apportionmentStatus: ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            ),
        ]);

        $findings = $this->evaluate(new NotApportionableCostRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('keine rechtliche Freigabe', $findings[0]->description);
        $this->assertFalse($findings[0]->context['hasReason']);
    }

    #[Test]
    public function pruefpflichtige_kosten_mit_begruendung_werden_gekennzeichnet(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Hauswart mit Mischtätigkeit',
                'KAT-HAUSWART',
                'Hauswart',
                $this->euros('1800.00'),
                apportionmentStatus: ApportionmentStatus::PRUEFPFLICHTIG,
                apportionmentOverrideReason: 'Nicht umlagefähige Tätigkeiten sind vertraglich abgegrenzt',
            ),
        ]);

        $findings = $this->evaluate(new NotApportionableCostRule, $context);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->context['hasReason']);
        $this->assertStringContainsString('prüfpflichtig', $findings[0]->description);
    }

    #[Test]
    public function sonstige_betriebskosten_mit_vertragsgrundlage_ergeben_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Dachrinnenreinigung',
                'KAT-SONSTIGE',
                'Sonstige Betriebskosten',
                $this->euros('240.00'),
                isOtherOperatingCosts: true,
                contractBasisRecognized: true,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new OtherOperatingCostsRule, $context));
    }

    #[Test]
    public function sonstige_betriebskosten_ohne_vertragsgrundlage_ergeben_eine_warnung(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem(
                    'pos-1',
                    'Dachrinnenreinigung',
                    'KAT-SONSTIGE',
                    'Sonstige Betriebskosten',
                    $this->euros('240.00'),
                    isOtherOperatingCosts: true,
                ),
            ],
            tenancies: [
                new RuleTenancy(
                    'miet-1',
                    'einheit-1',
                    'Herr Norbert Lindtner',
                    DatePeriodRange::calendarYear(2025),
                    otherOperatingCostsAgreed: false,
                ),
            ]
        );

        $findings = $this->evaluate(new OtherOperatingCostsRule, $context);

        $this->assertCount(2, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('keine Vertragsgrundlage erkannt', $findings[0]->description);
        $this->assertStringContainsString('Vereinbarung über sonstige Betriebskosten', $findings[1]->description);
    }

    #[Test]
    public function ausgewiesener_lohnanteil_ergibt_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Gartenpflege',
                'KAT-GARTEN',
                'Gartenpflege',
                $this->euros('480.00'),
                paragraph35aType: Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
                laborShareCent: 32000,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new Paragraph35aLaborShareRule, $context));
    }

    #[Test]
    public function fehlender_lohnanteil_ergibt_einen_hinweis_ohne_schaetzung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Gartenpflege',
                'KAT-GARTEN',
                'Gartenpflege',
                $this->euros('480.00'),
                paragraph35aType: Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
            ),
        ]);

        $findings = $this->evaluate(new Paragraph35aLaborShareRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertStringContainsString('nicht geschätzt', $findings[0]->description);
    }

    #[Test]
    public function aktiver_malware_scanner_ergibt_keinen_befund(): void
    {
        $context = $this->context(environment: new RuleEnvironment('clamav', true));

        $this->assertSame([], $this->evaluate(new MalwareScannerDisabledRule, $context));
    }

    #[Test]
    public function deaktivierter_malware_scanner_ergibt_produktiv_einen_hinweis(): void
    {
        $findings = $this->evaluate(
            new MalwareScannerDisabledRule,
            $this->context(environment: new RuleEnvironment('disabled', true))
        );

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertSame('AdminConfiguration', $findings[0]->entityType);
    }

    #[Test]
    public function ausserhalb_der_produktivumgebung_kein_scannerhinweis(): void
    {
        $findings = $this->evaluate(
            new MalwareScannerDisabledRule,
            $this->context(environment: new RuleEnvironment('disabled', false))
        );

        $this->assertSame([], $findings);
    }
}
