<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Enums\DeletionStatus;
use App\Enums\DocumentType;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;

/**
 * Testprovider ohne Netzwerkaufruf.
 *
 * Alle Beispielantworten sind frei erfunden und liegen in tests/Fixtures/Ai.
 */
final class FakeAiProviderTest extends TestCase
{
    public function test_provider_liefert_validierte_fixtures(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'hausgeldabrechnung',
            AiTestFactory::context(),
        ));

        self::assertSame(AiProviderKey::FAKE->value, $result->metadata->providerKey);
        self::assertTrue($result->isValidated());
        self::assertSame(372000, $result->field('hausgeldvorauszahlungen_cent')?->value);
        self::assertSame('hausgeldabrechnung', $result->metadata->schemaKey);
        self::assertSame(1, $result->metadata->attempts);
    }

    public function test_provider_legt_keine_providerdatei_an(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'grundsteuerbescheid',
            AiTestFactory::context(),
        ));

        self::assertCount(1, $result->providerFileDeletions);
        self::assertSame(DeletionStatus::NICHT_ERFORDERLICH, $result->providerFileDeletions[0]->status);
        self::assertFalse($result->providerFileDeletions[0]->isPrivacyAlert());
    }

    public function test_klassifikation_liefert_typisierten_dokumenttyp(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $result = $provider->classifyDocument(new ClassifyDocumentRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertSame(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $result->documentType);
        self::assertTrue($result->isValidated());
    }

    public function test_abweichende_fixture_kann_gesetzt_werden(): void
    {
        $provider = AiTestFactory::fakeProvider()
            ->withFixture('rechnung_bescheid', 'rechnung_bescheid_schemaverletzung.json');

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->requiresManualEntry());
        self::assertNotSame([], $result->violations);
    }

    public function test_ohne_kalkulationsbasis_wird_kein_preis_geraten(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'zaehlerwerte',
            AiTestFactory::context(),
        ));

        self::assertFalse($result->metadata->costBasisAvailable);
        self::assertNull($result->metadata->estimatedCostCent);
    }

    public function test_nicht_unterstuetzter_dateityp_wird_abgelehnt(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $this->expectException(UnsupportedFileTypeException::class);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            new DocumentPayload('Dokument 09 - Beispiel', 'application/zip', 'PK...', 1),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));
    }

    public function test_healthcheck_meldet_fehlende_produktionsfreigabe(): void
    {
        $provider = AiTestFactory::fakeProvider();

        $result = $provider->healthCheck(new HealthCheckRequest(AiTestFactory::context()));

        self::assertTrue($result->reachable);
        self::assertFalse($result->releasedForProduction);
        self::assertFalse($result->apiKeyConfigured);
        self::assertStringContainsString('Testprovider', $result->message);
    }
}
