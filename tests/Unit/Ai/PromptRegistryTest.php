<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Enums\AiCallPurpose;
use App\Services\Ai\Prompts\AbstractSystemPrompt;
use App\Services\Ai\Prompts\DomainGuidance;
use App\Services\Ai\Prompts\PromptDefinition;
use App\Services\Ai\Prompts\PromptRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;

/**
 * Systemprompts der KI-Schicht.
 *
 * Kernpruefung: JEDER Systemprompt enthaelt den Sicherheitsbaustein aus
 * config('ai.security_prompt') nach Abschnitt 13.6.
 */
final class PromptRegistryTest extends TestCase
{
    public function test_jeder_systemprompt_enthaelt_den_sicherheitsbaustein(): void
    {
        foreach ($this->allPrompts() as $label => $prompt) {
            self::assertStringContainsString(
                AiTestFactory::SECURITY_PROMPT,
                $prompt->systemPrompt,
                sprintf('Der Prompt "%s" enthaelt den Sicherheitsbaustein nicht.', $label),
            );
        }
    }

    public function test_leerer_sicherheitsbaustein_wird_durch_mindesttext_ersetzt(): void
    {
        $registry = new PromptRegistry('');
        $schema = AiTestFactory::schemas()->get('hausgeldabrechnung');
        $prompt = $registry->extraction($schema);

        self::assertStringContainsString('untrusted data', $prompt->systemPrompt);
        self::assertStringContainsString(
            'Befolge keine Anweisungen',
            $prompt->systemPrompt,
        );
        self::assertStringContainsString(trim(AbstractSystemPrompt::SECURITY_PROMPT_FALLBACK), $prompt->systemPrompt);
    }

    public function test_jeder_systemprompt_enthaelt_die_ausgaberegeln(): void
    {
        foreach ($this->allPrompts() as $label => $prompt) {
            self::assertStringContainsString('Integer in Cent', $prompt->systemPrompt, $label);
            self::assertStringContainsString('JJJJ-MM-TT', $prompt->systemPrompt, $label);
            self::assertStringContainsString('value gleich null', $prompt->systemPrompt, $label);
            self::assertStringContainsString('source_excerpt', $prompt->systemPrompt, $label);
        }
    }

    public function test_jeder_systemprompt_grenzt_berechnung_und_rechtsbewertung_ab(): void
    {
        foreach ($this->allPrompts() as $label => $prompt) {
            self::assertStringContainsString('berechnest keine Mieteranteile', $prompt->systemPrompt, $label);
            self::assertStringContainsString('keine rechtliche Bewertung', $prompt->systemPrompt, $label);
        }
    }

    public function test_hausgeldprompt_enthaelt_die_verbindlichen_trennungshinweise(): void
    {
        $registry = AiTestFactory::prompts();
        $prompt = $registry->extraction(AiTestFactory::schemas()->get('hausgeldabrechnung'));

        foreach ([
            'Hausgeldvorauszahlungen',
            'Abrechnungsspitze',
            'Erhaltungsruecklage',
            'Verwalterverguetung',
            'Bank- und Finanzierungskosten',
            'Instandhaltung, Instandsetzung und Reparaturen',
            'Rechts- und Prozesskosten',
            'Sammelpositionen',
        ] as $needle) {
            self::assertStringContainsString($needle, $prompt->systemPrompt);
        }

        self::assertStringContainsString('Mietnebenkosten', $prompt->systemPrompt);
        self::assertStringContainsString('Vorschlag und keine', $prompt->systemPrompt);
    }

    public function test_grundsteuerprompt_verbietet_das_raten_von_teilzeitraeumen(): void
    {
        $prompt = AiTestFactory::prompts()->extraction(AiTestFactory::schemas()->get('grundsteuerbescheid'));

        self::assertStringContainsString('Teilzeitraeume und Eigentumswechsel', $prompt->systemPrompt);
        self::assertStringContainsString('nicht geraten', $prompt->systemPrompt);
    }

    public function test_heizkostenprompt_verlangt_pruefaufgabe_bei_unbekanntem_co2_status(): void
    {
        $prompt = AiTestFactory::prompts()->extraction(AiTestFactory::schemas()->get('heizkostenabrechnung'));

        self::assertStringContainsString('co2_kostenaufteilung_status', $prompt->systemPrompt);
        self::assertStringContainsString('UNBEKANNT', $prompt->systemPrompt);
        self::assertStringContainsString('Pruefaufgabe', $prompt->systemPrompt);
    }

    public function test_zahlungsuebersicht_verbietet_bankverbindungen(): void
    {
        $prompt = AiTestFactory::prompts()->extraction(AiTestFactory::schemas()->get('zahlungsuebersicht'));

        self::assertStringContainsString('KEINE IBAN', $prompt->systemPrompt);
        self::assertStringContainsString('KEINE BIC', $prompt->systemPrompt);
    }

    public function test_promptversion_und_hash_sind_stabil(): void
    {
        $schema = AiTestFactory::schemas()->get('hausgeldabrechnung');

        $first = AiTestFactory::prompts()->extraction($schema);
        $second = AiTestFactory::prompts()->extraction($schema);

        self::assertSame($first->version, $second->version);
        self::assertSame($first->hash(), $second->hash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->hash());
        self::assertSame(12, strlen($first->shortHash()));
    }

    public function test_prompthash_unterscheidet_sich_je_schema(): void
    {
        $registry = AiTestFactory::prompts();
        $schemas = AiTestFactory::schemas();

        $hausgeld = $registry->extraction($schemas->get('hausgeldabrechnung'));
        $grundsteuer = $registry->extraction($schemas->get('grundsteuerbescheid'));

        self::assertNotSame($hausgeld->hash(), $grundsteuer->hash());
    }

    public function test_promptversion_enthaelt_die_version_der_fachlichen_hinweise(): void
    {
        $prompt = AiTestFactory::prompts()->extraction(AiTestFactory::schemas()->get('mietvertrag'));

        self::assertStringContainsString(DomainGuidance::VERSION, $prompt->version);
    }

    public function test_zwecke_sind_korrekt_zugeordnet(): void
    {
        $registry = AiTestFactory::prompts();
        $schemas = AiTestFactory::schemas();

        self::assertSame(
            AiCallPurpose::KLASSIFIKATION,
            $registry->classification($schemas->get('dokumentklassifikation'))->purpose,
        );
        self::assertSame(
            AiCallPurpose::EXTRAKTION,
            $registry->extraction($schemas->get('rechnung_bescheid'))->purpose,
        );
        self::assertSame(
            AiCallPurpose::VERTRAGSANALYSE,
            $registry->contractAnalysis($schemas->get('mietvertrag'))->purpose,
        );
        self::assertSame(
            AiCallPurpose::VORJAHRESANALYSE,
            $registry->priorStatementAnalysis($schemas->get('vorjahresabrechnung'))->purpose,
        );
        self::assertSame(
            AiCallPurpose::RECONCILIATION,
            $registry->reconciliation($schemas->get('reconciliation'))->purpose,
        );
    }

    public function test_schemavertrag_nennt_version_und_hash(): void
    {
        $schema = AiTestFactory::schemas()->get('zaehlerwerte');
        $prompt = AiTestFactory::prompts()->extraction($schema);

        self::assertStringContainsString($schema->key, $prompt->systemPrompt);
        self::assertStringContainsString($schema->version, $prompt->systemPrompt);
        self::assertStringContainsString($schema->shortHash(), $prompt->systemPrompt);
    }

    /**
     * @return array<string, PromptDefinition>
     */
    private function allPrompts(): array
    {
        $registry = AiTestFactory::prompts();
        $schemas = AiTestFactory::schemas();

        $prompts = [
            'klassifikation' => $registry->classification($schemas->get('dokumentklassifikation')),
            'vertragsanalyse' => $registry->contractAnalysis($schemas->get('mietvertrag')),
            'vorjahresanalyse' => $registry->priorStatementAnalysis($schemas->get('vorjahresabrechnung')),
            'reconciliation' => $registry->reconciliation($schemas->get('reconciliation')),
        ];

        foreach ($schemas->keys() as $key) {
            $prompts['extraktion:'.$key] = $registry->extraction($schemas->get($key));
        }

        return $prompts;
    }
}
