<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Enums\DeletionStatus;
use App\Enums\DocumentType;
use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\ReconcileDocumentsRequest;
use App\Services\Ai\Dto\ReconciliationSubject;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use App\Services\Ai\Exceptions\ProviderFileDeletionFailedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;
use App\Services\Ai\Providers\OpenAiResponsesProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\CollectingLogger;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Contracttests des OpenAI-Providers gegen gespeicherte, frei erfundene
 * Beispielantworten.
 *
 * Es findet KEIN Netzwerkaufruf und damit kein kostenpflichtiger
 * Providerzugriff statt. Der API-Key ist ein erkennbar unechter Platzhalter.
 */
final class OpenAiResponsesProviderTest extends TestCase
{
    public function test_anfrage_geht_an_die_responses_api_mit_store_false(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'hausgeldabrechnung',
            AiTestFactory::context(),
        ));

        $request = $http->requestAt(0);
        $body = $http->decodedBodyAt(0);

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.openai.com/v1/responses', $request->url);
        self::assertSame('Bearer '.AiTestFactory::API_KEY_PLACEHOLDER, $request->headers['authorization']);
        self::assertFalse($body['store'], 'store muss bei jeder Anfrage ausdruecklich false sein.');
        self::assertSame('gpt-5.6-terra', $body['model'], 'Die Hausgeldabrechnung nutzt das Analysemodell.');
        self::assertSame(16000, $body['max_output_tokens']);
    }

    public function test_structured_outputs_werden_streng_gesetzt(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('grundsteuerbescheid.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'grundsteuerbescheid',
            AiTestFactory::context(),
        ));

        $format = $http->decodedBodyAt(0)['text']['format'];

        self::assertSame('json_schema', $format['type']);
        self::assertSame('grundsteuerbescheid', $format['name']);
        self::assertTrue($format['strict']);
        self::assertFalse($format['schema']['additionalProperties']);
    }

    public function test_keine_batch_vectorstore_oder_toolfunktionen(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $body = $http->decodedBodyAt(0);

        foreach (['tools', 'tool_choice', 'background', 'previous_response_id', 'truncation', 'metadata'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $body);
        }
    }

    public function test_datei_wird_direkt_im_verarbeitungsrequest_uebergeben(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame(1, $http->callCount(), 'Ohne Files-API darf es nur eine Anfrage geben.');

        $content = $http->decodedBodyAt(0)['input'][1]['content'];

        self::assertSame('input_file', $content[0]['type']);
        self::assertSame('dokument.pdf', $content[0]['filename']);
        self::assertStringStartsWith('data:application/pdf;base64,', $content[0]['file_data']);
        self::assertArrayNotHasKey('file_id', $content[0]);

        self::assertCount(1, $result->providerFileDeletions);
        self::assertSame(DeletionStatus::NICHT_ERFORDERLICH, $result->providerFileDeletions[0]->status);
    }

    public function test_grosse_datei_laeuft_ueber_die_files_api_und_wird_sofort_geloescht(): void
    {
        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_beispiel_0001', 'object' => 'file'])
            ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')))
            ->pushJson(['id' => 'file_beispiel_0001', 'deleted' => true, 'object' => 'file']);

        $provider = AiTestFactory::openAiProvider($http, inlineMaxBytes: 10);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame([
            'POST https://api.openai.com/v1/files',
            'POST https://api.openai.com/v1/responses',
            'DELETE https://api.openai.com/v1/files/file_beispiel_0001',
        ], $http->urls());

        $upload = $http->requestAt(0);
        self::assertTrue($upload->isMultipart());

        $partNames = array_map(
            static fn ($part): string => $part->name,
            $upload->multipart,
        );

        self::assertContains('purpose', $partNames);
        self::assertContains('expires_after[anchor]', $partNames);
        self::assertContains('expires_after[seconds]', $partNames);
        self::assertSame(
            (string) OpenAiResponsesProvider::SHORTEST_FILE_EXPIRY_SECONDS,
            $upload->multipart[2]->contents(),
        );

        self::assertSame('input_file', $http->decodedBodyAt(1)['input'][1]['content'][0]['type']);
        self::assertSame('file_beispiel_0001', $http->decodedBodyAt(1)['input'][1]['content'][0]['file_id']);

        self::assertCount(1, $result->providerFileDeletions);
        self::assertSame(DeletionStatus::ERFOLGREICH, $result->providerFileDeletions[0]->status);
        self::assertSame(16, strlen($result->providerFileDeletions[0]->providerFileHandleHash));
        self::assertStringNotContainsString(
            'file_beispiel_0001',
            (string) json_encode($result->providerFileDeletions[0]->toLogContext()),
            'Die Provider-Datei-ID darf nicht im Loeschprotokoll stehen.',
        );

        $result->assertProviderFilesDeleted();
        $this->addToAssertionCount(1);
    }

    public function test_fehlgeschlagene_loeschung_wird_als_datenschutzalarm_gemeldet(): void
    {
        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_beispiel_0002', 'object' => 'file'])
            ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')))
            ->pushJson(['error' => ['code' => 'server_error']], 500);

        $collector = new CollectingLogger;
        $provider = AiTestFactory::openAiProvider($http, $collector, inlineMaxBytes: 10);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated(), 'Die Extraktion bleibt gueltig, die Loeschung ist getrennt.');
        self::assertSame(DeletionStatus::FEHLGESCHLAGEN, $result->providerFileDeletions[0]->status);
        self::assertTrue($result->providerFileDeletions[0]->isPrivacyAlert());
        self::assertStringContainsString('Datenschutzalarm', $collector->dump());

        $this->expectException(ProviderFileDeletionFailedException::class);
        $result->assertProviderFilesDeleted();
    }

    public function test_loeschung_erfolgt_auch_bei_technischem_fehler(): void
    {
        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_beispiel_0003', 'object' => 'file'])
            ->pushJson(['error' => ['code' => 'server_error']], 500)
            ->pushJson(['id' => 'file_beispiel_0003', 'deleted' => true]);

        $provider = AiTestFactory::openAiProvider($http, inlineMaxBytes: 10);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                AiTestFactory::pdfPayload(),
                'rechnung_bescheid',
                AiTestFactory::context(),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderTransportException) {
            self::assertSame(
                'DELETE https://api.openai.com/v1/files/file_beispiel_0003',
                $http->urls()[2],
                'Auch bei endgueltigem Fehler wird die Providerdatei sofort geloescht.',
            );
        }
    }

    public function test_hausgeldabrechnung_wird_vollstaendig_uebernommen(): void
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
        self::assertSame('hausgeldabrechnung', $result->metadata->schemaKey);
        self::assertSame(372000, $result->field('hausgeldvorauszahlungen_cent')?->value);
        self::assertSame(18450, $result->field('abrechnungsspitze_cent')?->value);
        self::assertSame(30240, $result->field('verwalterverguetung_cent')?->value);
        self::assertSame(96000, $result->field('ruecklagenzufuehrung_cent')?->value);
        self::assertSame(42300, $result->field('instandhaltung_reparatur_cent')?->value);
        self::assertSame('SAMMELPOSITION_UNBEZEICHNET', $result->field('kostenarten[3].kategorie')?->value);
        self::assertNull($result->field('grundsteuer_enthalten')?->value);
    }

    public function test_grundsteuerbescheid_wird_uebernommen(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('grundsteuerbescheid.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'grundsteuerbescheid',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame(24960, $result->field('jahresbetrag_cent')?->value);
        self::assertSame('2025-01-01', $result->field('zeitraum_von')?->value);
        self::assertSame('KZ-0000-0000-0001', $result->field('aktenzeichen')?->value);
        self::assertFalse($result->field('betrifft_teilzeitraum')?->value);
    }

    public function test_mietvertrag_wird_analysiert(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('mietvertrag.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->analyzeContract(new AnalyzeContractRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame('gpt-5.6-terra', $result->metadata->model, 'Vertraege nutzen das Analysemodell.');
        self::assertSame('WOHNRAUM', $result->field('nutzungsart')?->value);
        self::assertSame(14000, $result->field('betriebskostenvorauszahlung_monatlich_cent')?->value);
        self::assertSame('WOHNFLAECHE', $result->field('standardverteilerschluessel')?->value);
        self::assertFalse($result->field('dezentrale_energieversorgung')?->value);
    }

    public function test_externe_heizkostenabrechnung_wird_uebernommen(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('heizkostenabrechnung.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'heizkostenabrechnung',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame(1690000, $result->field('gesamtkosten_summe_cent')?->value);
        self::assertSame('UNBEKANNT', $result->field('co2_kostenaufteilung_status')?->value);
        self::assertContains(
            'co2_kostenaufteilung_status',
            $result->reviewRequiredPaths(),
            'Ein unbekannter CO2-Status ist pruefpflichtig.',
        );
        self::assertSame(129950, $result->field('einheiten[0].summe_cent')?->value);
    }

    public function test_rechnung_wird_uebernommen_und_material_ist_kein_lohnanteil(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame(214200, $result->field('gesamtbetrag_brutto_cent')?->value);
        self::assertSame(150000, $result->field('positionen[0].lohnanteil_cent')?->value);
        self::assertNull($result->field('positionen[1].lohnanteil_cent')?->value);
    }

    public function test_vorjahresabrechnung_wird_analysiert(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('vorjahresabrechnung.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->analyzePriorStatement(new AnalyzePriorStatementRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame(-7600, $result->field('ergebnis_cent')?->value);
        self::assertSame('2024-01-01', $result->field('abrechnungszeitraum_von')?->value);
    }

    public function test_klassifikation_liefert_typisierten_dokumenttyp(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('dokumentklassifikation.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->classifyDocument(new ClassifyDocumentRequest(
            AiTestFactory::pdfPayload(),
            AiTestFactory::context(),
        ));

        self::assertTrue($result->isValidated());
        self::assertSame(DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, $result->documentType);
        self::assertSame(0.94, $result->confidence);
        self::assertFalse($result->requiresReview());
        self::assertFalse($result->containsInstructionLikeText);
        self::assertSame('gpt-5.6-luna', $result->metadata()->model, 'Klassifikation nutzt das guenstigere Modell.');
        self::assertCount(1, $result->alternatives);
    }

    public function test_abgleich_sendet_nur_strukturierte_daten_und_keine_datei(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('reconciliation.json')),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->reconcileDocuments(new ReconcileDocumentsRequest(
            [
                new ReconciliationSubject(
                    'Dokument 01 - Hausgeldabrechnung',
                    DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL,
                    'hausgeldabrechnung',
                    ['heizkosten_anteil_einheit_cent' => ['value' => 98750]],
                ),
                new ReconciliationSubject(
                    'Dokument 03 - Heizkostenabrechnung',
                    DocumentType::HEIZKOSTENABRECHNUNG,
                    'heizkostenabrechnung',
                    ['gesamtkosten_summe_cent' => ['value' => 1690000]],
                ),
            ],
            AiTestFactory::context(),
            '2025-01-01',
            '2025-12-31',
        ));

        $content = $http->decodedBodyAt(0)['input'][1]['content'];

        self::assertCount(1, $content, 'Der Abgleich sendet keine Datei, nur Text.');
        self::assertSame('input_text', $content[0]['type']);
        self::assertStringContainsString('Dokument 01 - Hausgeldabrechnung', $content[0]['text']);

        self::assertTrue($result->isValidated());
        self::assertCount(3, $result->matrixRows());
        self::assertCount(2, $result->findings());
        self::assertTrue($result->extraction->field('heizkosten_moeglicherweise_doppelt')?->value);
        self::assertNull($result->extraction->field('grundsteuer_moeglicherweise_doppelt')?->value);
    }

    public function test_metadaten_enthalten_token_kosten_und_versionen(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid.json'), 100_000, 20_000),
        );

        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context('korrelation-4711'),
        ));

        $metadata = $result->metadata;

        self::assertSame('openai', $metadata->providerKey);
        self::assertSame('gpt-5.6-luna', $metadata->model);
        self::assertSame(100_000, $metadata->inputTokens);
        self::assertSame(20_000, $metadata->outputTokens);
        self::assertTrue($metadata->costBasisAvailable);
        // (100.000 * 100 + 20.000 * 500) / 1000 = 20.000 Tausendstel-Cent
        self::assertSame(20_000, $metadata->estimatedCostMilliCent);
        self::assertSame(20, $metadata->estimatedCostCent);
        self::assertSame('korrelation-4711', $metadata->correlationId);
        self::assertSame('resp_beispiel_0001', $metadata->providerRequestId);
        self::assertSame(1, $metadata->attempts);
        self::assertSame(200, $metadata->httpStatusCode);
        self::assertNotSame('', $metadata->promptHash);
        self::assertNotSame('', (string) $metadata->schemaHash);
    }

    public function test_ratenbegrenzung_wirft_rate_limit_exception(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            ['error' => ['code' => 'rate_limit_exceeded']],
            429,
            ['retry-after' => '30'],
        );

        $provider = AiTestFactory::openAiProvider($http);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                AiTestFactory::pdfPayload(),
                'rechnung_bescheid',
                AiTestFactory::context(),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (RateLimitException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds());
            self::assertStringContainsString('openai', $exception->getMessage());
        }
    }

    public function test_nicht_unterstuetzter_dateityp_wird_lokal_abgelehnt(): void
    {
        $http = new RecordingAiHttpClient;
        $provider = AiTestFactory::openAiProvider($http);

        $payload = new DocumentPayload('Dokument 09 - Beispiel', 'application/zip', 'PK...', 1);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                $payload,
                'rechnung_bescheid',
                AiTestFactory::context(),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (UnsupportedFileTypeException $exception) {
            self::assertSame(0, $http->callCount(), 'Es darf kein Dokumentinhalt uebertragen werden.');
            self::assertStringContainsString('application/zip', $exception->getMessage());
        }
    }

    public function test_unerwartete_antwortstruktur_wird_als_transportfehler_gemeldet(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['id' => 'resp_ohne_output']);
        $provider = AiTestFactory::openAiProvider($http);

        $this->expectException(ProviderTransportException::class);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));
    }

    public function test_tagesbudget_blockiert_vor_dem_versand(): void
    {
        $http = new RecordingAiHttpClient;
        $provider = AiTestFactory::openAiProvider($http, dailyLimitCent: 1);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                AiTestFactory::pdfPayload(),
                'rechnung_bescheid',
                AiTestFactory::context('korrelation-0002', 900, 500_000),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (DailyCostLimitExceededException) {
            self::assertSame(0, $http->callCount(), 'Bei ausgeschoepftem Budget wird nichts uebertragen.');
        }
    }

    public function test_healthcheck_prueft_die_modellverfuegbarkeit(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['id' => 'gpt-5.6-luna', 'object' => 'model']);
        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->healthCheck(new HealthCheckRequest(AiTestFactory::context()));

        self::assertSame('GET https://api.openai.com/v1/models/gpt-5.6-luna', $http->urls()[0]);
        self::assertTrue($result->reachable);
        self::assertTrue($result->modelAvailable);
        self::assertFalse($result->releasedForProduction, 'Die Freigabe ergaenzt der Router.');
        self::assertTrue($result->apiKeyConfigured);
    }

    public function test_healthcheck_meldet_fehlendes_modell(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['error' => ['code' => 'model_not_found']], 404);
        $provider = AiTestFactory::openAiProvider($http);

        $result = $provider->healthCheck(new HealthCheckRequest(AiTestFactory::context(), true));

        self::assertSame('GET https://api.openai.com/v1/models/gpt-5.6-terra', $http->urls()[0]);
        self::assertTrue($result->reachable);
        self::assertFalse($result->modelAvailable);
        self::assertFalse($result->isUsable());
        self::assertStringContainsString('nicht verfuegbar', $result->message);
    }
}
