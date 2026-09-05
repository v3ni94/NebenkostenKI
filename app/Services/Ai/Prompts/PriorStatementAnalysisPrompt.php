<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Enums\AiCallPurpose;

/**
 * Systemprompt der Vorjahresanalyse.
 *
 * Vorjahreswerte dienen ausschliesslich dem Vergleich und werden niemals als
 * neue Kosten uebernommen (Abschnitt 8.3).
 */
final class PriorStatementAnalysisPrompt extends AbstractSystemPrompt
{
    public const VERSION = '1.0.0';

    public function purpose(): AiCallPurpose
    {
        return AiCallPurpose::VORJAHRESANALYSE;
    }

    public function version(): string
    {
        return sprintf('%s+hinweise-%s', self::VERSION, DomainGuidance::VERSION);
    }

    protected function roleBlock(): string
    {
        return 'Du analysierst eine Betriebskostenabrechnung eines Vorjahres. Das Ergebnis dient '
            .'ausschliesslich dem Vergleich mit dem aktuellen Abrechnungszeitraum, zum Beispiel fuer die '
            .'Erkennung fehlender Kostenarten und ungewoehnlicher Abweichungen.';
    }

    protected function guidanceBlock(): string
    {
        return DomainGuidance::priorStatement();
    }

    protected function userInstruction(): string
    {
        return 'Analysiere die beigefuegte Vorjahresabrechnung und gib ausschliesslich das JSON-Objekt nach '
            .'dem vorgegebenen Schema aus.';
    }
}
