<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\AiProvider;
use App\Enums\DocumentType;
use App\Models\AiCall;
use App\Models\AiPromptVersion;
use App\Models\ExtractedField;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Integration\PromptVersionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Nachweise zu Abschnitt 6.4 und 13.8: je Aufruf ein Nachweisdatensatz mit
 * Tokenzahlen, Kosten und Dauer, aber ohne Prompt und ohne Antwort.
 *
 * Der Provider laeuft ueber einen aufgezeichneten Transportadapter. Es findet
 * KEIN Netzwerkaufruf und damit kein kostenpflichtiger Providerzugriff statt.
 * Der API-Key ist ein erkennbar unechter Platzhalter.
 */
class AiCallRecordingTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    /**
     * Textfragmente aus der Beispielantwort und aus dem Systemprompt. Keines
     * davon darf in ai_calls landen.
     *
     * @var list<string>
     */
    private const VERBOTENE_FRAGMENTE = [
        'Beispielverwaltung Musterstadt GmbH',
        'Vorauszahlungen 3.720,00',
        'WEG Beispielweg 7',
        'Wohnung 4, 2. OG rechts',
        'source_excerpt',
        'confidence',
        'untrusted data',
        'JSON-Schema',
        'Fehlende Angaben sind null',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_ai_calls_traegt_provider_modell_tokenzahlen_kosten_und_dauer(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json'), 4200, 900),
        );

        $outcome = $this->extractor(
            $this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI)
        )->extract($document, $upload);

        $this->assertTrue($outcome->successful);

        $call = AiCall::query()->firstOrFail();

        $this->assertSame(AiProvider::OPENAI, $call->getAttribute('provider'));
        $this->assertSame('gpt-5.6-terra', $call->getAttribute('model'));
        $this->assertSame(AiCallPurpose::EXTRAKTION, $call->getAttribute('purpose'));
        $this->assertSame(AiCallStatus::ERFOLGREICH, $call->getAttribute('status'));
        $this->assertSame('resp_beispiel_0001', $call->getAttribute('request_id'));
        $this->assertSame(4200, $call->getAttribute('input_tokens'));
        $this->assertSame(900, $call->getAttribute('output_tokens'));
        $this->assertSame(1, $call->getAttribute('file_count'));
        $this->assertTrue($call->getAttribute('schema_valid'));
        $this->assertSame(1, $call->getAttribute('attempt'));
        $this->assertNull($call->getAttribute('error_code'));

        // Rechenweg: 4.200 Eingabetoken zu 200 US-Cent je Million ergibt
        // 840 Tausendstel-Cent, 900 Ausgabetoken zu 1.000 US-Cent je Million
        // ergeben 900 Tausendstel-Cent. Summe 1.740, aufgerundet 2 Cent.
        $this->assertSame(2, $call->getAttribute('cost_cent'));
        $this->assertIsInt($call->getAttribute('duration_ms'));
        $this->assertSame($document->getKey(), $call->getAttribute('document_id'));
        $this->assertSame($document->getAttribute('billing_run_id'), $call->getAttribute('billing_run_id'));
    }

    public function test_ai_calls_enthaelt_keinen_prompt_und_keine_antwort(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $this->extractor($this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $call = AiCall::query()->firstOrFail();

        // Es gibt strukturell keine Spalte fuer Prompt oder Antwort.
        $spalten = array_keys($call->getAttributes());

        foreach (['prompt', 'response', 'request_body', 'response_body', 'payload', 'raw'] as $verboten) {
            $this->assertNotContains($verboten, $spalten);
        }

        // Und keine Spalte traegt ein Fragment aus Prompt oder Antwort.
        foreach ($call->getAttributes() as $spalte => $wert) {
            if (! is_string($wert)) {
                continue;
            }

            foreach (self::VERBOTENE_FRAGMENTE as $fragment) {
                $this->assertStringNotContainsString(
                    $fragment,
                    $wert,
                    sprintf('Die Spalte "%s" enthaelt ein Fragment aus Prompt oder Antwort.', $spalte),
                );
            }
        }

        $this->assertNull($call->getAttribute('error_message'));
    }

    public function test_extrahierte_felder_verweisen_auf_den_ki_aufruf(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $this->extractor($this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $call = AiCall::query()->firstOrFail();

        $feld = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', 'hausgeldvorauszahlungen_cent')
            ->firstOrFail();

        // Provider, Modell und Promptversion haengen ueber diesen Verweis am
        // Feld, ohne in extracted_fields dupliziert zu werden.
        $this->assertSame($call->getKey(), $feld->getAttribute('ai_call_id'));

        $promptVersion = AiPromptVersion::query()->whereKey($call->getAttribute('ai_prompt_version_id'))->firstOrFail();

        $this->assertSame(AiCallPurpose::EXTRAKTION, $promptVersion->getAttribute('purpose'));
        $this->assertTrue($promptVersion->getAttribute('is_active'));
    }

    public function test_schemaverletzung_nach_den_reparaturversuchen_wird_protokolliert(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        $fehlerhaft = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft))
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft))
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft));

        $outcome = $this->extractor(
            $this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI)
        )->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertTrue($outcome->permanent);
        $this->assertSame('SCHEMA_UNGUELTIG', $outcome->errorCode);

        // Ein Erstversuch und zwei kontrollierte Reparaturversuche.
        $this->assertSame(3, $http->callCount());

        $call = AiCall::query()->firstOrFail();

        $this->assertSame(AiCallStatus::SCHEMA_FEHLER, $call->getAttribute('status'));
        $this->assertFalse($call->getAttribute('schema_valid'));
        $this->assertSame(3, $call->getAttribute('attempt'));
        $this->assertSame('SCHEMA_UNGUELTIG', $call->getAttribute('error_code'));
        $this->assertStringContainsString('manuell', (string) $call->getAttribute('error_message'));

        // Es wird nichts halb Geprueftes persistiert.
        $this->assertSame(0, ExtractedField::query()->count());
    }

    public function test_der_testprovider_erscheint_nicht_als_externe_verarbeitung(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $outcome = $this->extractor($this->router($this->testProvider(), AiProviderKey::FAKE))
            ->extract($document, $upload);

        $this->assertTrue($outcome->successful);

        // Der Testprovider fuehrt keinen Netzwerkaufruf aus und ist kein
        // Provider im Sinne von App\Enums\AiProvider. Er erscheint deshalb
        // nicht in ai_calls; die Promptversion wird trotzdem gefuehrt.
        $this->assertSame(0, AiCall::query()->count());
        $this->assertSame(1, AiPromptVersion::query()->count());
        $this->assertNull(
            ExtractedField::query()->where('document_id', $document->getKey())->value('ai_call_id'),
        );
    }

    public function test_promptwechsel_deaktiviert_die_bisherige_version(): void
    {
        $registrar = new PromptVersionRegistrar;

        $alt = $registrar->register(AiCallPurpose::EXTRAKTION, '1.0.0', str_repeat('a', 64));
        $neu = $registrar->register(AiCallPurpose::EXTRAKTION, '1.1.0', str_repeat('b', 64));

        $this->assertNotNull($alt);
        $this->assertNotNull($neu);

        $alt->refresh();
        $neu->refresh();

        $this->assertFalse($alt->getAttribute('is_active'));
        $this->assertTrue($neu->getAttribute('is_active'));
        $this->assertNotNull($alt->getAttribute('deactivated_at'));
    }

    public function test_geaenderter_prompthash_ohne_versionswechsel_wird_vermerkt(): void
    {
        $registrar = new PromptVersionRegistrar;

        $registrar->register(AiCallPurpose::KLASSIFIKATION, '1.0.0', str_repeat('a', 64));

        // Ein zweiter Registrar, damit nicht der Prozesscache antwortet.
        (new PromptVersionRegistrar)->register(AiCallPurpose::KLASSIFIKATION, '1.0.0', str_repeat('c', 64));

        $version = AiPromptVersion::query()->where('purpose', AiCallPurpose::KLASSIFIKATION->value)->firstOrFail();

        $this->assertSame(str_repeat('c', 64), $version->getAttribute('hash'));
        $this->assertSame(PromptVersionRegistrar::HASH_MISMATCH_NOTE, $version->getAttribute('notes'));
        $this->assertSame(1, AiPromptVersion::query()->count());
    }

    public function test_klassifikation_und_extraktion_erzeugen_je_einen_nachweis(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $klassifikationsAntwort = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('dokumentklassifikation.json'), 900, 120),
        );

        $this->classifier($this->router(AiTestFactory::openAiProvider($klassifikationsAntwort), AiProviderKey::OPENAI))
            ->classify($document, $upload);

        $extraktionsAntwort = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $this->extractor($this->router(AiTestFactory::openAiProvider($extraktionsAntwort), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $zwecke = AiCall::query()->orderBy('created_at')->pluck('purpose')->all();

        $this->assertCount(2, $zwecke);
        $this->assertContains(AiCallPurpose::KLASSIFIKATION, $zwecke);
        $this->assertContains(AiCallPurpose::EXTRAKTION, $zwecke);

        // Klassifikation laeuft nach Abschnitt 13.8 auf dem guenstigeren Modell.
        $klassifikation = AiCall::query()->where('purpose', AiCallPurpose::KLASSIFIKATION->value)->firstOrFail();

        $this->assertSame('gpt-5.6-luna', $klassifikation->getAttribute('model'));
    }
}
