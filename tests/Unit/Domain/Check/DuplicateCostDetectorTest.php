<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Check;

use App\Domain\Calculation\Check\DuplicateCostDetector;
use App\Domain\Calculation\Check\InvoiceReference;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use App\Domain\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dublettenprüfung: gleicher Belegfingerabdruck, gleiche Rechnungsnummer oder
 * gleiche Kombination aus Lieferant, Betrag und Datum
 * (Pflichtenheft Abschnitt 12.5).
 */
final class DuplicateCostDetectorTest extends TestCase
{
    private DuplicateCostDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new DuplicateCostDetector;
    }

    #[Test]
    public function gleiche_rechnungsnummer_desselben_lieferanten_ist_eine_dublette(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference(
                'k-5',
                'Gebäudeversicherung',
                Money::fromEuros('640.00'),
                'Rheinische Versicherung AG',
                'R-2025-118',
                '2025-03-14'
            ),
            new InvoiceReference(
                'k-6',
                'Gebäudeversicherung (Doppelerfassung)',
                Money::fromEuros('640.00'),
                'Rheinische Versicherung AG',
                'R-2025-118',
                '2025-03-14'
            ),
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(CheckCode::DUPLICATE_COST_SUSPECTED, $findings[0]->code);
        $this->assertSame(CheckSeverity::WARNING, $findings[0]->severity);
        $this->assertSame('k-5', $findings[0]->context['costItemKey']);
        $this->assertSame('k-6', $findings[0]->context['duplicateOf']);
        $this->assertStringContainsString('gleiche Rechnungsnummer', $findings[0]->message);
    }

    #[Test]
    public function gleicher_belegfingerabdruck_ist_eine_dublette(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference('k-1', 'Müllgebühren', Money::fromEuros('186.40'), null, null, null, 'a1b2c3'),
            new InvoiceReference('k-2', 'Müllgebühren', Money::fromEuros('186.40'), null, null, null, 'a1b2c3'),
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Belegfingerabdruck', $findings[0]->message);
    }

    #[Test]
    public function gleicher_lieferant_betrag_und_datum_ist_eine_dublette(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference('k-1', 'Gartenpflege März', Money::fromEuros('310.00'), 'Grünwerk GmbH', 'A-1', '2025-03-31'),
            new InvoiceReference('k-2', 'Gartenpflege', Money::fromEuros('310.00'), 'Grünwerk GmbH', 'A-2', '2025-03-31'),
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('gleiches Belegdatum', $findings[0]->message);
    }

    #[Test]
    public function unterschiedliche_belege_sind_keine_dublette(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference('k-1', 'Gartenpflege März', Money::fromEuros('310.00'), 'Grünwerk GmbH', 'A-1', '2025-03-31'),
            new InvoiceReference('k-2', 'Gartenpflege Juni', Money::fromEuros('290.00'), 'Grünwerk GmbH', 'A-2', '2025-06-30'),
        ]);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function gutschrift_zur_rechnung_ist_keine_dublette(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference('k-1', 'Gartenpflege', Money::fromEuros('1200.00'), 'Grünwerk GmbH', 'A-1', '2025-03-31'),
            new InvoiceReference(
                'k-2',
                'Gutschrift Gartenpflege',
                Money::fromEuros('-181.00'),
                'Grünwerk GmbH',
                'A-1',
                '2025-03-31',
                null,
                true,
                'k-1'
            ),
        ]);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function mehrere_dubletten_werden_einzeln_gemeldet(): void
    {
        $findings = $this->detector->detect([
            new InvoiceReference('k-1', 'Versicherung', Money::fromEuros('640.00'), 'V AG', 'R-1', '2025-01-10'),
            new InvoiceReference('k-2', 'Versicherung', Money::fromEuros('640.00'), 'V AG', 'R-1', '2025-01-10'),
            new InvoiceReference('k-3', 'Versicherung', Money::fromEuros('640.00'), 'V AG', 'R-1', '2025-01-10'),
        ]);

        $this->assertCount(3, $findings);
    }
}
