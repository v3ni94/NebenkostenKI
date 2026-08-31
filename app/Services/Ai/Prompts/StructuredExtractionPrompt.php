<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Enums\AiCallPurpose;

/**
 * Systemprompt der strukturierten Extraktion.
 *
 * Der Prompt ist schemagebunden: die fachlichen Hinweise stammen aus
 * DomainGuidance und richten sich nach dem Schemaschluessel. Damit erhaelt
 * eine Hausgeldabrechnung die verbindlichen Trennungs- und Warnhinweise aus
 * Abschnitt 7.2, ein Grundsteuerbescheid die Hinweise aus Abschnitt 7.3 und
 * eine Heizkostenabrechnung die Hinweise aus Abschnitt 7.4.
 *
 * Die Promptversion setzt sich aus der eigenen Version und der Version der
 * fachlichen Hinweise zusammen, damit eine Aenderung der Hinweise
 * nachvollziehbar zu einer neuen Promptversion fuehrt.
 */
final class StructuredExtractionPrompt extends AbstractSystemPrompt
{
    public const VERSION = '1.0.0';

    public function __construct(
        string $securityPrompt,
        private readonly string $schemaKey,
    ) {
        parent::__construct($securityPrompt);
    }

    public function purpose(): AiCallPurpose
    {
        return AiCallPurpose::EXTRAKTION;
    }

    public function version(): string
    {
        return sprintf('%s+hinweise-%s', self::VERSION, DomainGuidance::VERSION);
    }

    protected function roleBlock(): string
    {
        return 'Du extrahierst strukturierte Werte aus einer Unterlage einer deutschen '
            .'Betriebskostenabrechnung. Du arbeitest genau, konservativ und nachvollziehbar. Wenn eine Angabe '
            .'nicht eindeutig im Dokument steht, gibst du null aus und begruendest das nicht.';
    }

    protected function guidanceBlock(): string
    {
        return DomainGuidance::forSchema($this->schemaKey);
    }
}
