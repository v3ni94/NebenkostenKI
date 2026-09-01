<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Dto\FinalViewBundle;
use App\Models\CalculationSnapshot;

/**
 * Aufbereitung des gesperrten Berechnungsstandes im Testlauf.
 *
 * Die produktive Umsetzung liegt im Berechnungs- und Vorschaupaket. Der Fake
 * liefert feste, frei erfundene Beispieldaten und merkt sich, mit welchem
 * Snapshot er aufgerufen wurde. Damit ist nachweisbar, dass die Finalversion
 * aus dem Snapshot entsteht.
 */
final class FakeFinalDocumentViews implements FinalDocumentViews
{
    /**
     * @var list<string>
     */
    public array $calledWith = [];

    public function __construct(private readonly FinalViewBundle $bundle) {}

    public function forSnapshot(CalculationSnapshot $snapshot): FinalViewBundle
    {
        $this->calledWith[] = (string) $snapshot->getKey();

        return $this->bundle;
    }
}
