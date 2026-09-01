<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating;

use App\Domain\Calculation\Heating\ExternalHeatingReconciler;
use App\Domain\Calculation\Heating\HeatingSupplyType;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fall C: dezentrale Versorgung. Der Mieter bezieht die Energie direkt, es
 * werden keine Heizkosten als Vermieterkosten angesetzt.
 */
final class DecentralizedSupplyTest extends TestCase
{
    #[Test]
    public function fall_c_erzeugt_keine_heizkostenzeilen(): void
    {
        self::assertFalse(HeatingSupplyType::DECENTRALIZED->producesHeatingLines());
        self::assertTrue(HeatingSupplyType::EXTERNAL_STATEMENT->producesHeatingLines());
        self::assertTrue(HeatingSupplyType::CENTRAL_WITHOUT_STATEMENT->producesHeatingLines());
    }

    #[Test]
    public function fall_c_wird_im_pruefbericht_sachlich_ausgewiesen(): void
    {
        $findings = (new ExternalHeatingReconciler)->decentralizedSupply();

        self::assertCount(1, $findings);
        self::assertSame(CheckCode::HEATING_DECENTRALIZED_NO_COSTS, $findings[0]->code);
        self::assertSame(CheckSeverity::INFO, $findings[0]->severity);
        self::assertStringContainsString('keine', $findings[0]->message);
        self::assertFalse($findings[0]->blocksFinalization());
    }

    #[Test]
    public function fall_b_ist_als_manuelle_erfassung_beschrieben(): void
    {
        self::assertSame(
            'Zentralheizung ohne externe Abrechnung',
            HeatingSupplyType::CENTRAL_WITHOUT_STATEMENT->label()
        );
    }
}
