<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCostItem;
use App\Rules\Definitions\CreditNoteRule;
use App\Rules\Definitions\DuplicateCostRule;
use App\Rules\Definitions\PropertyTaxDuplicateRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Dubletten, Gutschriften und mehrfach erfasste Grundsteuer.
 */
final class DuplicateRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function unterschiedliche_belege_ergeben_keine_dublette(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Wasserabschlag Januar', 'KAT-WASSER', 'Wasserversorgung', $this->euros('300.00'), supplier: 'Stadtwerke Rosendorf', invoiceNumber: 'R-1'),
            new RuleCostItem('pos-2', 'Wasserabschlag Februar', 'KAT-WASSER', 'Wasserversorgung', $this->euros('310.00'), supplier: 'Stadtwerke Rosendorf', invoiceNumber: 'R-2'),
        ]);

        $this->assertSame([], $this->evaluate(new DuplicateCostRule, $context));
    }

    #[Test]
    public function doppelte_rechnungsnummer_ergibt_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Aufzugwartung', 'KAT-AUFZUG', 'Aufzug', $this->euros('600.00'), supplier: 'Aufzugtechnik Ohlwein KG', invoiceNumber: 'R-77'),
            new RuleCostItem('pos-2', 'Aufzugwartung', 'KAT-AUFZUG', 'Aufzug', $this->euros('600.00'), supplier: 'Aufzugtechnik Ohlwein KG', invoiceNumber: 'R-77'),
        ]);

        $findings = $this->evaluate(new DuplicateCostRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertSame('pos-2', $findings[0]->context['duplicateOf']);
    }

    #[Test]
    public function gleicher_betrag_mit_gleichem_datum_ergibt_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Gartenpflege', 'KAT-GARTEN', 'Gartenpflege', $this->euros('240.00'), supplier: 'Gartenpflege Weidenbach GbR', documentDate: '2025-05-12'),
            new RuleCostItem('pos-2', 'Gartenpflege Mai', 'KAT-GARTEN', 'Gartenpflege', $this->euros('240.00'), supplier: 'Gartenpflege Weidenbach GbR', documentDate: '2025-05-12'),
        ]);

        $findings = $this->evaluate(new DuplicateCostRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('gleicher Betrag', $findings[0]->description);
    }

    #[Test]
    public function gleicher_dateifingerabdruck_ergibt_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Sachversicherung', 'KAT-VERS', 'Sachversicherung', $this->euros('820.00'), fingerprint: 'hmac-abc'),
            new RuleCostItem('pos-2', 'Versicherungsbeitrag', 'KAT-VERS', 'Sachversicherung', $this->euros('820.00'), fingerprint: 'hmac-abc'),
        ]);

        $findings = $this->evaluate(new DuplicateCostRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Belegfingerabdruck', $findings[0]->description);
    }

    #[Test]
    public function zugeordnete_gutschrift_ergibt_einen_hinweis(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Hauswartleistung', 'KAT-HAUSWART', 'Hauswart', $this->euros('1200.00')),
            new RuleCostItem('pos-2', 'Gutschrift Hauswartleistung', 'KAT-HAUSWART', 'Hauswart', $this->euros('-200.00'), isCreditNote: true, relatedInvoiceKey: 'pos-1'),
        ]);

        $findings = $this->evaluate(new CreditNoteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
    }

    #[Test]
    public function gutschrift_ohne_zugeordnete_rechnung_ergibt_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-2', 'Gutschrift unbekannt', 'KAT-HAUSWART', 'Hauswart', $this->euros('-200.00'), isCreditNote: true),
        ]);

        $findings = $this->evaluate(new CreditNoteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
    }

    #[Test]
    public function einmalige_grundsteuer_ergibt_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Grundsteuer 2025', 'KAT-GRUNDSTEUER', 'Grundsteuer', $this->euros('640.00'), isPropertyTax: true),
        ]);

        $this->assertSame([], $this->evaluate(new PropertyTaxDuplicateRule, $context));
    }

    #[Test]
    public function doppelte_grundsteuer_ergibt_eine_warnung_ohne_addition(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Grundsteuer 2025', 'KAT-GRUNDSTEUER', 'Grundsteuer', $this->euros('640.00'), isPropertyTax: true),
            new RuleCostItem('pos-2', 'Grundsteuer aus Hausgeldabrechnung', 'KAT-GRUNDSTEUER', 'Grundsteuer', $this->euros('640.00'), isPropertyTax: true),
        ]);

        $findings = $this->evaluate(new PropertyTaxDuplicateRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('1.280,00 EUR wird nicht automatisch angesetzt', $findings[0]->description);
        $this->assertSame(2, $findings[0]->context['occurrences']);
    }
}
