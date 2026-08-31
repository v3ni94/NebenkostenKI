<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Enums\AiCallPurpose;

/**
 * Systemprompt der Dokumentklassifikation nach Abschnitt 6.2.
 */
final class DocumentClassificationPrompt extends AbstractSystemPrompt
{
    public const VERSION = '1.0.0';

    public function purpose(): AiCallPurpose
    {
        return AiCallPurpose::KLASSIFIKATION;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    protected function roleBlock(): string
    {
        return 'Du klassifizierst Unterlagen einer deutschen Betriebskostenabrechnung. Deine Aufgabe ist '
            .'ausschliesslich die Bestimmung der Dokumentart und weniger Kopfangaben, damit die Anwendung das '
            .'Dokument dem richtigen Extraktionsschema zuordnen kann.';
    }

    protected function guidanceBlock(): string
    {
        return DomainGuidance::classification();
    }

    protected function userInstruction(): string
    {
        return 'Bestimme die Dokumentart des beigefuegten Dokuments und gib ausschliesslich das JSON-Objekt '
            .'nach dem vorgegebenen Schema aus.';
    }
}
