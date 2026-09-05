<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\ConfidenceEvaluator;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Konfidenzkennzeichnung nach Abschnitt 6.5.
 *
 * Ab einer Konfidenz unter ai.confidence_review_threshold ist eine
 * ausdrueckliche Pruefung erforderlich. Die Schicht kennzeichnet, sie
 * korrigiert nicht.
 */
final class ConfidenceEvaluatorTest extends TestCase
{
    public function test_wert_unter_dem_schwellenwert_ist_pruefpflichtig(): void
    {
        $evaluator = new ConfidenceEvaluator(0.80);

        self::assertTrue($evaluator->requiresReview($this->value(0.79)));
        self::assertFalse($evaluator->requiresReview($this->value(0.80)));
        self::assertFalse($evaluator->requiresReview($this->value(0.95)));
    }

    public function test_fehlender_wert_ist_immer_pruefpflichtig(): void
    {
        $evaluator = new ConfidenceEvaluator(0.80);

        self::assertTrue($evaluator->requiresReview(new ExtractedValue('feld', null, 1.0, 1, 'Fundstelle')));
    }

    public function test_wert_ohne_seitenangabe_ist_pruefpflichtig(): void
    {
        $evaluator = new ConfidenceEvaluator(0.80);

        self::assertTrue(
            $evaluator->requiresReview(new ExtractedValue('feld', 12345, 0.99, null, null)),
            'Ein Wert ohne Quellenbezug ist nach Grundsatz 2 nicht uebernehmbar.',
        );
    }

    public function test_markierung_veraendert_den_wert_nicht(): void
    {
        $evaluator = new ConfidenceEvaluator(0.80);

        $marked = $evaluator->mark([
            'niedrig' => $this->value(0.4),
            'hoch' => $this->value(0.99),
        ]);

        self::assertTrue($marked['niedrig']->requiresReview);
        self::assertFalse($marked['hoch']->requiresReview);
        self::assertSame(214242, $marked['niedrig']->value);
        self::assertSame(0.4, $marked['niedrig']->confidence);
        self::assertSame('Fundstelle', $marked['niedrig']->sourceExcerpt);
    }

    public function test_pruefpflichtige_pfade_werden_aufgelistet(): void
    {
        $evaluator = new ConfidenceEvaluator(0.80);

        $paths = $evaluator->reviewRequiredPaths([
            'a' => $this->value(0.2),
            'b' => $this->value(0.9),
            'c' => new ExtractedValue('c', null, 0.0, null, null),
        ]);

        self::assertSame(['a', 'c'], $paths);
    }

    public function test_schwellenwert_ist_konfigurierbar(): void
    {
        $streng = new ConfidenceEvaluator(0.95);
        $locker = new ConfidenceEvaluator(0.50);

        self::assertTrue($streng->requiresReview($this->value(0.9)));
        self::assertFalse($locker->requiresReview($this->value(0.9)));
        self::assertSame(0.95, $streng->threshold());
    }

    public function test_ergebnis_dto_meldet_pruefpflichtige_und_fehlende_felder(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'hausgeldabrechnung',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());

        // grundsteuer_enthalten ist in der Fixture null mit Konfidenz 0,42.
        self::assertContains('grundsteuer_enthalten', $result->reviewRequiredPaths());
        self::assertContains('grundsteuer_enthalten', $result->missingPaths());

        // Die Sammelposition ohne Bezeichnung liegt unter dem Schwellenwert.
        self::assertContains('kostenarten[3].bezeichnung', $result->reviewRequiredPaths());

        // Hochkonfidente Felder bleiben unmarkiert.
        self::assertNotContains('abrechnungszeitraum_von', $result->reviewRequiredPaths());
        self::assertFalse($result->field('abrechnungszeitraum_von')?->requiresReview);
    }

    private function value(float $confidence): ExtractedValue
    {
        return new ExtractedValue('feld', 214242, $confidence, 1, 'Fundstelle');
    }
}
