<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\StatementCalculator;
use App\Models\CalculationSnapshot;

/**
 * Reproduktion eines gespeicherten Berechnungsstands.
 *
 * VERBINDLICH (ARCHITECTURE.md Abschnitt 6): Ein bezahlter Berechnungsstand
 * muss jederzeit reproduzierbar sein. Aus der im Snapshot gespeicherten
 * normalisierten Eingabe wird dieselbe Domaineingabe erzeugt und dieselbe
 * Berechnung ausgeführt. Es wird ausschließlich der Snapshot gelesen; die
 * aktuellen Modelldaten bleiben unberührt. Damit bleibt der Stand auch nach
 * einer späteren Änderung der Stammdaten oder einer Gesetzesänderung
 * nachvollziehbar.
 *
 * Die Finalversion der PDFs entsteht aus diesem Weg und niemals durch
 * Entfernen eines Wasserzeichens.
 */
final class RecalculateFromSnapshot
{
    public function __construct(
        private readonly SnapshotSerializer $serializer,
        private readonly StatementCalculator $calculator,
    ) {}

    public function input(CalculationSnapshot $snapshot): StatementCalculationInput
    {
        $payload = $snapshot->getAttribute('input');

        if (! is_array($payload) || $payload === []) {
            throw CalculationInputException::snapshotNotReproducible((string) $snapshot->getKey());
        }

        /** @var array<string, mixed> $payload */
        return $this->serializer->hydrate($payload);
    }

    public function handle(CalculationSnapshot $snapshot): CalculationRunResult
    {
        return $this->calculator->calculate($this->input($snapshot));
    }

    /**
     * Erneut berechnetes Ergebnis in derselben normalisierten Form wie im
     * Snapshot. Der Vergleich beider Nutzlasten weist die Reproduzierbarkeit
     * feldweise nach.
     *
     * @return array<string, mixed>
     */
    public function reproducedResultPayload(CalculationSnapshot $snapshot): array
    {
        return $this->serializer->result($this->handle($snapshot));
    }

    /**
     * Prüft, ob der Snapshot bitgenau reproduzierbar ist.
     */
    public function isReproducible(CalculationSnapshot $snapshot): bool
    {
        $stored = $snapshot->getAttribute('result');

        if (! is_array($stored)) {
            return false;
        }

        return $this->serializer->canonical($stored)
            === $this->serializer->canonical($this->reproducedResultPayload($snapshot));
    }
}
