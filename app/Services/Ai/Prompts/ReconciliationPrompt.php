<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Enums\AiCallPurpose;

/**
 * Systemprompt des Dokumentabgleichs nach Abschnitt 7.4.
 *
 * Der Abgleich arbeitet ausschliesslich mit bereits validierten
 * strukturierten Extraktionsdaten, nicht mit Originaldateien. Diese sind zu
 * diesem Zeitpunkt bereits geloescht.
 */
final class ReconciliationPrompt extends AbstractSystemPrompt
{
    public const VERSION = '1.0.0';

    public function purpose(): AiCallPurpose
    {
        return AiCallPurpose::RECONCILIATION;
    }

    public function version(): string
    {
        return sprintf('%s+hinweise-%s', self::VERSION, DomainGuidance::VERSION);
    }

    protected function roleBlock(): string
    {
        return 'Du gleichst mehrere bereits ausgelesene Unterlagen eines Abrechnungslaufs gegeneinander ab, '
            .'insbesondere WEG-Hausgeldabrechnung, Grundsteuerbescheid, externe Heizkostenabrechnung und '
            .'Einzelbelege. Du erkennst moegliche Dubletten, Zeitraumabweichungen, Summenabweichungen und '
            .'fehlende Angaben.';
    }

    protected function guidanceBlock(): string
    {
        return DomainGuidance::reconciliation();
    }

    protected function userInstruction(): string
    {
        return 'Gleiche die uebergebenen strukturierten Extraktionsdaten ab und gib ausschliesslich das '
            .'JSON-Objekt nach dem vorgegebenen Schema aus.';
    }
}
