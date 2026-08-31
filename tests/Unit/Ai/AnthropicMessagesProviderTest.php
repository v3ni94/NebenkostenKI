<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Enums\DeletionStatus;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Providers\AnthropicMessagesProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Contracttests des Anthropic-Providers gegen gespeicherte, frei erfundene
 * Beispielantworten.
 *
 * Es findet KEIN Netzwerkaufruf statt. Der API-Key ist ein erkennbar unechter
 * Platzhalter.
 */
final class AnthropicMessagesProviderTest extends TestCase
{
    public function test_header_folgen_der_offiziellen_form(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'hausgeldabrechnung',
            AiTestFactory::context(),
        ));

        $request = $http->requestAt(0);

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.anthropic.com/v1/messages', $request->url);
        self::assertSame(AiTestFactory::API_KEY_PLACEHOLDER, $request->headers['x-api-key']);
        self::assertSame('2023-06-01', $request->headers['anthropic-version']);
        self::assertSame('application/json', $request->headers['content-type']);
        self::assertArrayNotHasKey('authorization', $request->headers);
        self::assertArrayNotHasKey('anthropic-beta', $request->headers);
    }

    public function test_pdf_wird_als_document_block_direkt_uebergeben(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $body = $http->decodedBodyAt(0);
        $content = $body['messages'][0]['content'];

        self::assertSame(1, $http->callCount());
        self::assertSame('claude-haiku-4-5', $body['model']);
        self::assertSame(16000, $body['max_tokens']);
        self::assertIsString($body['system']);
        self::assertSame('document', $content[0]['type']);
        self::assertSame('base64', $content[0]['source']['type']);
        self::assertSame('application/pdf', $content[0]['source']['media_type']);
        self::assertNotSame('', $content[0]['source']['data']);
        self::assertSame('text', $content[1]['type']);

        self::assertSame(DeletionStatus::NICHT_ERFORDERLICH, $result->providerFileDeletions[0]->status);
    }

    public function test_bild_wird_als_image_block_uebergeben(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $payload = new DocumentPayload(
            'Dokument 05 - Beispielfoto',
            DocumentPayload::MIME_JPEG,
            'frei erfundener Bildinhalt',
            1,
        );

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            $payload,
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $content = $http->decodedBodyAt(0)['messages'][0]['content'];

        self::assertSame('image', $content[0]['type']);
        self::assertSame('image/jpeg', $content[0]['source']['media_type']);
    }

    public function test_keine_batch_oder_code_execution_felder(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $body = $http->decodedBodyAt(0);

        foreach (['tools', 'tool_choice', 'container', 'mcp_servers', 'context_management'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $body);
        }
    }

    public function test_structured_outputs_sind_standardmaessig_aus(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        self::assertFalse($provider->usesStructuredOutputs());

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertArrayNotHasKey('output_config', $http->decodedBodyAt(0));
        self::assertTrue(
            $result->isValidated(),
            'Die strikte Schemaausgabe wird ueber Prompt und serverseitige Validierung erzwungen.',
        );
    }

    public function test_structured_outputs_koennen_zugeschaltet_werden(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http, useStructuredOutputs: true);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $body = $http->decodedBodyAt(0);

        self::assertTrue($provider->usesStructuredOutputs());
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame('rechnung_bescheid', $body['output_config']['format']['name']);
        self::assertSame(
            AnthropicMessagesProvider::STRUCTURED_OUTPUTS_BETA,
            $http->requestAt(0)->headers['anthropic-beta'],
        );
    }

    public function test_grosse_datei_laeuft_ueber_die_files_api_und_wird_sofort_geloescht(): void
    {
        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_anthropic_0001', 'type' => 'file'])
            ->pushJson(AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json')))
            ->pushJson(['id' => 'file_anthropic_0001', 'type' => 'file_deleted']);

        $provider = AiTestFactory::anthropicProvider($http, inlineMaxBytes: 10);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame([
            'POST https://api.anthropic.com/v1/files',
            'POST https://api.anthropic.com/v1/messages',
            'DELETE https://api.anthropic.com/v1/files/file_anthropic_0001',
        ], $http->urls());

        $content = $http->decodedBodyAt(1)['messages'][0]['content'];

        self::assertSame('document', $content[0]['type']);
        self::assertSame('file', $content[0]['source']['type']);
        self::assertSame('file_anthropic_0001', $content[0]['source']['file_id']);

        self::assertSame(DeletionStatus::ERFOLGREICH, $result->providerFileDeletions[0]->status);
        $result->assertProviderFilesDeleted();
        $this->addToAssertionCount(1);
    }

    public function test_ablehnung_des_modells_wird_ohne_inhaltsuebernahme_gemeldet(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson([
            'id' => 'msg_beispiel_0002',
            'stop_reason' => 'refusal',
            'content' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 0],
        ]);

        $provider = AiTestFactory::anthropicProvider($http);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                AiTestFactory::pdfPayload(),
                'rechnung_bescheid',
                AiTestFactory::context(),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderTransportException $exception) {
            self::assertStringContainsString('refusal', $exception->getMessage());
            self::assertStringNotContainsString('Beispielweg', $exception->getMessage());
        }
    }

    public function test_ueberlastung_wird_als_transportfehler_gemeldet(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['error' => ['type' => 'overloaded_error']], 529);
        $provider = AiTestFactory::anthropicProvider($http);

        try {
            $provider->extractStructuredData(new ExtractStructuredDataRequest(
                AiTestFactory::pdfPayload(),
                'rechnung_bescheid',
                AiTestFactory::context(),
            ));
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderTransportException $exception) {
            self::assertStringContainsString('529', $exception->getMessage());
            self::assertStringContainsString('overloaded_error', $exception->getMessage());
        }
    }

    public function test_ratenbegrenzung_wirft_rate_limit_exception(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['error' => ['type' => 'rate_limit_error']], 429);
        $provider = AiTestFactory::anthropicProvider($http);

        $this->expectException(RateLimitException::class);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));
    }

    public function test_healthcheck_nutzt_die_modelle_api_mit_version_header(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['id' => 'claude-haiku-4-5', 'type' => 'model']);
        $provider = AiTestFactory::anthropicProvider($http);

        $result = $provider->healthCheck(new HealthCheckRequest(AiTestFactory::context()));

        self::assertSame('GET https://api.anthropic.com/v1/models/claude-haiku-4-5', $http->urls()[0]);
        self::assertSame('2023-06-01', $http->requestAt(0)->headers['anthropic-version']);
        self::assertTrue($result->modelAvailable);
    }

    public function test_analysemodell_wird_fuer_komplexe_schemata_verwendet(): void
    {
        $http = (new RecordingAiHttpClient)->setDefaultJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('mietvertrag.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'mietvertrag',
            AiTestFactory::context(),
        ));

        self::assertSame('claude-sonnet-5', $result->metadata->model);
    }

    public function test_extraktionsmodell_wird_fuer_einfache_schemata_verwendet(): void
    {
        $http = (new RecordingAiHttpClient)->setDefaultJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('zaehlerwerte.json')),
        );

        $provider = AiTestFactory::anthropicProvider($http);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'zaehlerwerte',
            AiTestFactory::context(),
        ));

        self::assertSame('claude-haiku-4-5', $result->metadata->model);
    }
}
