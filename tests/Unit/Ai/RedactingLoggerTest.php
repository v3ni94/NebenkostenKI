<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\AiHttpResponse;
use App\Services\Ai\Providers\RawProviderResponse;
use App\Services\Ai\RedactingLogger;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\CollectingLogger;

/**
 * Protokollierung der KI-Schicht.
 *
 * Verbindlich: Anfrage- und Antwortbodies duerfen weder in Logs noch im Error
 * Monitoring, Debugger oder Queue-Payload gespeichert werden.
 */
final class RedactingLoggerTest extends TestCase
{
    public function test_nicht_freigegebene_schluessel_werden_verworfen(): void
    {
        $collector = new CollectingLogger;
        $logger = new RedactingLogger($collector);

        $logger->info('Testmeldung', [
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'request_body' => '{"input":"Beispielweg 7, 40000 Musterstadt"}',
            'response_body' => 'Erika Beispiel, Bankverbindung aus dem Beispieldokument',
            'ocr_text' => 'vollstaendiger OCR-Text',
            'api_key' => 'darf-nicht-im-log-stehen',
        ]);

        $context = $collector->records[0]['context'];

        self::assertSame(['provider', 'model', 'redacted_keys'], array_keys($context));
        self::assertSame(4, $context['redacted_keys']);
        self::assertStringNotContainsString('Beispielweg', $collector->dump());
        self::assertStringNotContainsString('Erika Beispiel', $collector->dump());
        self::assertStringNotContainsString('darf-nicht-im-log-stehen', $collector->dump());
    }

    public function test_freigegebene_metadaten_bleiben_erhalten(): void
    {
        $collector = new CollectingLogger;
        $logger = new RedactingLogger($collector);

        $logger->warning('Testmeldung', [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'purpose' => 'EXTRAKTION',
            'input_tokens' => 4200,
            'output_tokens' => 900,
            'estimated_cost_cent' => 1,
            'duration_ms' => 812,
            'correlation_id' => 'korrelation-0001',
            'status_code' => 200,
            'violation_codes' => ['BETRAG_NICHT_INTEGER'],
        ]);

        $context = $collector->records[0]['context'];

        self::assertSame('anthropic', $context['provider']);
        self::assertSame(4200, $context['input_tokens']);
        self::assertSame(['BETRAG_NICHT_INTEGER'], $context['violation_codes']);
        self::assertArrayNotHasKey('redacted_keys', $context);
    }

    public function test_zeichenketten_werden_hart_gekuerzt(): void
    {
        $context = RedactingLogger::redact([
            'error_code' => str_repeat('X', RedactingLogger::MAX_VALUE_LENGTH + 500),
        ]);

        self::assertSame(RedactingLogger::MAX_VALUE_LENGTH, mb_strlen((string) $context['error_code']));
    }

    public function test_listen_werden_begrenzt_und_objekte_verworfen(): void
    {
        $paths = [];

        for ($i = 0; $i < RedactingLogger::MAX_LIST_ITEMS + 20; $i++) {
            $paths[] = 'pfad_'.$i;
        }

        $context = RedactingLogger::redact([
            'violation_paths' => $paths,
            'model' => new \stdClass,
        ]);

        self::assertIsArray($context['violation_paths']);
        self::assertCount(RedactingLogger::MAX_LIST_ITEMS, $context['violation_paths']);
        self::assertArrayNotHasKey('model', $context);
        self::assertSame(1, $context['redacted_keys']);
    }

    public function test_ohne_logger_wird_nichts_geschrieben(): void
    {
        $logger = new RedactingLogger;
        $logger->error('Testmeldung', ['provider' => 'openai']);

        $this->addToAssertionCount(1);
    }

    public function test_document_payload_gibt_im_debug_keinen_inhalt_aus(): void
    {
        $payload = AiTestFactory::pdfPayload();
        $debug = $payload->__debugInfo();

        self::assertSame('[redigiert]', $debug['contents']);
        self::assertSame('Dokument 01 - Beispiel', $debug['neutralLabel']);
        self::assertStringNotContainsString('Frei erfundener Beispielinhalt', (string) json_encode($debug));
    }

    public function test_document_payload_kann_nicht_serialisiert_werden(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Queue-Payloads/');

        serialize(AiTestFactory::pdfPayload());
    }

    public function test_http_request_kann_nicht_serialisiert_werden(): void
    {
        $request = AiHttpRequest::json('POST', 'https://beispiel.invalid/v1/responses', [], ['input' => 'x'], 30);

        self::assertSame('[redigiert]', $request->__debugInfo()['body']);

        $this->expectException(LogicException::class);
        serialize($request);
    }

    public function test_http_response_kann_nicht_serialisiert_werden(): void
    {
        $response = new AiHttpResponse(200, '{"geheim":"Dokumentinhalt"}');

        self::assertSame('[redigiert]', $response->__debugInfo()['body']);
        self::assertStringNotContainsString('Dokumentinhalt', (string) json_encode($response->__debugInfo()));

        $this->expectException(LogicException::class);
        serialize($response);
    }

    public function test_rohe_modellantwort_kann_nicht_serialisiert_werden(): void
    {
        $raw = new RawProviderResponse('{"geheim":"Dokumentinhalt"}', 100, 20, 'resp_0001');

        self::assertSame('[redigiert]', $raw->__debugInfo()['jsonPayload']);

        $this->expectException(LogicException::class);
        serialize($raw);
    }

    public function test_die_allowlist_enthaelt_keine_inhaltsschluessel(): void
    {
        // api_key_configured ist ausdruecklich zulaessig: es ist ein
        // Wahrheitswert darueber, ob ein Key konfiguriert ist, und niemals der
        // Key selbst.
        $erlaubteAusnahmen = ['api_key_configured'];

        foreach (RedactingLogger::ALLOWED_KEYS as $key) {
            if (in_array($key, $erlaubteAusnahmen, true)) {
                continue;
            }

            foreach (['body', 'payload', 'prompt_text', 'response', 'ocr', 'excerpt', 'api_key', 'iban', 'secret'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $key,
                    sprintf('Der Schluessel "%s" koennte Inhalte durchlassen.', $key),
                );
            }
        }
    }
}
