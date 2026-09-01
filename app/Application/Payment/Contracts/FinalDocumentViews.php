<?php

declare(strict_types=1);

namespace App\Application\Payment\Contracts;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Models\CalculationSnapshot;

/**
 * Nahtstelle zwischen Finalisierung und Berechnungspaket.
 *
 * Die Finalisierung erzeugt alle Final-PDFs VOLLSTAENDIG NEU aus dem
 * gesperrten Calculation Snapshot (Abschnitt 9 Schritt 12, 14.3). Die
 * Aufbereitung des Snapshots zu Darstellungsobjekten gehoert fachlich in das
 * Berechnungs- und Vorschaupaket, das denselben Weg fuer die Vorschau
 * verwendet. Genau derselbe Weg muss es sein, sonst koennten Vorschau und
 * Finalversion inhaltlich auseinanderlaufen.
 *
 * Die Umsetzung wird im Container gebunden. Fehlt die Bindung, bricht die
 * Finalisierung mit einer klaren Meldung ab; sie erzeugt insbesondere KEINE
 * ersatzweise berechneten Werte.
 */
interface FinalDocumentViews
{
    public function forSnapshot(CalculationSnapshot $snapshot): FinalViewBundle;
}
