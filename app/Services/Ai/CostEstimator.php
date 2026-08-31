<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Schaetzt die Kosten eines KI-Aufrufs nach Abschnitt 13.8.
 *
 * DOKUMENTIERTE ANNAHME: Die Kalkulationsbasis in
 * ai.cost_basis_us_cent_per_million_tokens ist eine Annahme zum Projektstand.
 * Modellnamen und Preise sind veraenderlich. Die Basis ist vor Livegang und
 * danach regelmaessig gegen die offizielle Preisliste des jeweiligen
 * Providers zu pruefen. Ein Modellwechsel wird mit Modell-ID und
 * Promptversion protokolliert (ADR-009).
 *
 * Ist fuer ein Modell keine Basis konfiguriert, wird KEIN Preis geraten. Die
 * Schaetzung ist dann als nicht verfuegbar gekennzeichnet, damit der
 * Adminbereich die fehlende Basis melden kann. Eine geratene Null waere eine
 * stille Annahme und wuerde das Tagesbudget aushebeln.
 */
final class CostEstimator
{
    /**
     * @param  array<string, array{input: int, output: int}>  $basisUsCentPerMillionTokens
     */
    public function __construct(
        private readonly array $basisUsCentPerMillionTokens,
    ) {}

    public static function fromConfig(AiConfig $config): self
    {
        return new self($config->costBasisUsCentPerMillionTokens);
    }

    public function hasBasisFor(string $model): bool
    {
        return array_key_exists($model, $this->basisUsCentPerMillionTokens);
    }

    /**
     * @return list<string>
     */
    public function modelsWithBasis(): array
    {
        return array_keys($this->basisUsCentPerMillionTokens);
    }

    public function estimate(string $model, int $inputTokens, int $outputTokens): CostEstimate
    {
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);

        if (! $this->hasBasisFor($model)) {
            return CostEstimate::withoutBasis($model, $inputTokens, $outputTokens);
        }

        $basis = $this->basisUsCentPerMillionTokens[$model];

        // Rechenweg, offengelegt:
        //   Kosten in US-Cent = Token / 1.000.000 * US-Cent je Million Token
        //   Kosten in Tausendstel-Cent = Token * US-Cent je Million / 1.000
        $milliCent = (int) round(
            ($inputTokens * $basis['input'] + $outputTokens * $basis['output']) / 1000
        );

        return new CostEstimate($model, $inputTokens, $outputTokens, $milliCent, true);
    }

    /**
     * Vorabschaetzung fuer die Budgetpruefung vor dem Versand.
     *
     * Die Ausgabetoken sind vor dem Aufruf unbekannt. Es wird deshalb
     * bewusst mit der vollen zulaessigen Ausgabelaenge gerechnet, also mit
     * dem schlechtesten Fall. Eine Unterschaetzung wuerde das Tagesbudget
     * aushebeln.
     */
    public function estimateWorstCase(string $model, int $estimatedInputTokens, int $maxOutputTokens): CostEstimate
    {
        return $this->estimate($model, $estimatedInputTokens, $maxOutputTokens);
    }
}
