<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Enums\AiCallStatus;
use App\Services\Ai\Dto\AiResultStatus;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\SchemaViolationCode;
use App\Services\Ai\Exceptions\SchemaValidationFailedException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\CollectingLogger;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Kontrollierte Reparaturversuche bei Schemaverletzung.
 *
 * Vorgabe: bei ai.max_retries = 2 sind ein erster Versuch und genau zwei
 * Reparaturversuche zulaessig. Danach Status FEHLGESCHLAGEN als Rueckgabewert
 * mit manueller Erfassung als Rueckfallweg, keine unbehandelte Ausnahme.
 *
 * Alle Beispielantworten sind frei erfunden. Es findet kein Netzwerkaufruf
 * statt.
 */
final class SchemaRepairRetryTest extends TestCase
{
    public function test_genau_zwei_reparaturversuche_und_dann_fehlgeschlagen(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid));

        $collector = new CollectingLogger;
        $provider = AiTestFactory::openAiProvider($http, $collector, maxRetries: 2);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame(3, $http->callCount(), 'Ein Versuch plus genau zwei Reparaturversuche.');
        self::assertSame(AiResultStatus::FEHLGESCHLAGEN, $result->status);
        self::assertTrue($result->requiresManualEntry());
        self::assertSame(3, $result->metadata->attempts);
        self::assertSame(AiCallStatus::SCHEMA_FEHLER, $result->metadata->status);
        self::assertNotSame([], $result->violations);
    }

    public function test_reparaturversuch_enthaelt_die_verletzten_pfade(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 2);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $firstBody = (string) $http->requestAt(0)->body();
        $secondBody = (string) $http->requestAt(1)->body();

        self::assertStringNotContainsString('nicht schemakonform', $firstBody);
        self::assertStringContainsString('nicht schemakonform', $secondBody);
        self::assertStringContainsString('gesamtbetrag_brutto_cent.value', $secondBody);
        self::assertStringContainsString('Integer in Cent', $secondBody);
    }

    public function test_reparaturversuch_spielt_die_vorherige_antwort_nicht_zurueck(): void
    {
        $invalid = AiTestFactory::fixtureArray('rechnung_bescheid_schemaverletzung.json');
        $invalid['aussteller']['source_excerpt'] = 'GEHEIMER FUNDSTELLENTEXT AUS DEM DOKUMENT';
        $invalidJson = (string) json_encode($invalid, JSON_THROW_ON_ERROR);

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalidJson))
            ->pushJson(AiTestFactory::openAiResponseBody($invalidJson))
            ->pushJson(AiTestFactory::openAiResponseBody($invalidJson));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 2);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        for ($index = 1; $index < $http->callCount(); $index++) {
            self::assertStringNotContainsString(
                'GEHEIMER FUNDSTELLENTEXT',
                (string) $http->requestAt($index)->body(),
                'Die vorherige Modellantwort darf nicht zurueckgespielt werden.',
            );
        }
    }

    public function test_gueltige_antwort_im_zweiten_versuch_beendet_die_schleife(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');
        $valid = AiTestFactory::fixture('rechnung_bescheid.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($valid));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 2);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame(2, $http->callCount());
        self::assertSame(AiResultStatus::VALIDIERT, $result->status);
        self::assertSame(2, $result->metadata->attempts);
        self::assertSame([], $result->violations);
    }

    public function test_ohne_reparaturversuche_wird_nur_einmal_gesendet(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)->pushJson(AiTestFactory::openAiResponseBody($invalid));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 0);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame(1, $http->callCount());
        self::assertSame(AiResultStatus::FEHLGESCHLAGEN, $result->status);
    }

    public function test_nicht_dekodierbare_antwort_gilt_als_schemaverletzung(): void
    {
        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody('Das ist keine JSON-Antwort.'))
            ->pushJson(AiTestFactory::openAiResponseBody('Immer noch kein JSON.'))
            ->pushJson(AiTestFactory::openAiResponseBody('Auch jetzt kein JSON.'));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 2);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        self::assertSame(3, $http->callCount());
        self::assertSame(AiResultStatus::FEHLGESCHLAGEN, $result->status);
        self::assertSame(
            SchemaViolationCode::UNGUELTIGES_JSON,
            $result->violations[0]->code,
        );
    }

    public function test_require_validated_data_wirft_ausnahme_ohne_dokumentinhalt(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid));

        $provider = AiTestFactory::openAiProvider($http, maxRetries: 2);

        $result = $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        try {
            $result->requireValidatedData();
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (SchemaValidationFailedException $exception) {
            self::assertStringContainsString('rechnung_bescheid', $exception->getMessage());
            self::assertStringContainsString('3 Versuchen', $exception->getMessage());
            self::assertContains('gesamtbetrag_brutto_cent.value', $exception->violationPaths());
            self::assertContains(SchemaViolationCode::BETRAG_NICHT_INTEGER->value, $exception->violationCodes());
            self::assertStringNotContainsString('Hausmeisterservice', $exception->getMessage());
            self::assertStringNotContainsString('2142.42', $exception->getMessage());
        }
    }

    public function test_reparaturprotokoll_enthaelt_nur_metadaten(): void
    {
        $invalid = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid))
            ->pushJson(AiTestFactory::openAiResponseBody($invalid));

        $collector = new CollectingLogger;
        $provider = AiTestFactory::openAiProvider($http, $collector, maxRetries: 2);

        $provider->extractStructuredData(new ExtractStructuredDataRequest(
            AiTestFactory::pdfPayload(),
            'rechnung_bescheid',
            AiTestFactory::context(),
        ));

        $dump = $collector->dump();

        self::assertGreaterThan(0, $collector->count());
        self::assertStringContainsString('BETRAG_NICHT_INTEGER', $dump);
        self::assertStringNotContainsString('Hausmeisterservice', $dump);
        self::assertStringNotContainsString('Beispielweg', $dump);
        self::assertStringNotContainsString('RG-2025-0042', $dump);
    }
}
