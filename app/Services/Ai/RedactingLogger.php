<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Protokollierung der KI-Schicht mit harter Allowlist.
 *
 * VERBINDLICHE DATENSCHUTZREGEL (Grundsatz 4, Abschnitte 6.4 und 13.5):
 * Anfrage- und Antwortbodies duerfen weder in Application Logs noch im Error
 * Monitoring, Debugger oder Queue-Payload gespeichert werden. Freigegeben
 * sind ausschliesslich technische Metadaten.
 *
 * Diese Klasse setzt das technisch durch, statt sich auf Disziplin zu
 * verlassen: der Kontext wird gegen eine Allowlist gefiltert. Alles, was
 * nicht ausdruecklich freigegeben ist, wird verworfen und nur als Anzahl
 * gemeldet. Zusaetzlich werden Zeichenketten hart gekuerzt, damit auch ein
 * versehentlich freigegebener Schluessel keinen Textblock durchlaesst.
 *
 * Die Meldung selbst darf keinen Dokumentinhalt enthalten. Sie wird von
 * dieser Schicht immer als feste technische Zeichenkette gebildet, niemals
 * aus Provider- oder Dokumentdaten zusammengesetzt.
 */
final class RedactingLogger
{
    /**
     * Freigegebene Kontextschluessel. Nur diese erreichen das Log.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'provider',
        'primary_provider',
        'fallback_provider',
        'model',
        'purpose',
        'result_status',
        'prompt_version',
        'prompt_hash',
        'schema_key',
        'schema_version',
        'schema_hash',
        'input_tokens',
        'output_tokens',
        'estimated_cost_cent',
        'estimated_cost_milli_cent',
        'cost_basis_available',
        'duration_ms',
        'request_id',
        'correlation_id',
        'attempts',
        'attempt',
        'status_code',
        'error_code',
        'http_method',
        'endpoint',
        'violation_codes',
        'violation_paths',
        'violation_count',
        'review_required_count',
        'missing_value_count',
        'deletion_status',
        'provider_file_handle_hash',
        'attempted_at',
        'fallback_used',
        'fallback_reason',
        'fallback_blocked',
        'dual_review_used',
        'conflict_count',
        'conflict_paths',
        'document_label',
        'document_mime_type',
        'document_byte_size',
        'document_page_count',
        'reachable',
        'model_available',
        'released_for_production',
        'api_key_configured',
        'redacted_keys',
    ];

    /**
     * Harte Laengengrenze je Zeichenkette im Kontext.
     */
    public const MAX_VALUE_LENGTH = 200;

    /**
     * Harte Laengengrenze je Listeneintrag im Kontext.
     */
    public const MAX_LIST_ITEMS = 30;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, self::redact($context));
    }

    /**
     * Filtert einen Kontext auf die freigegebenen Metadaten.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $clean = [];
        $dropped = 0;

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                $dropped++;

                continue;
            }

            $sanitized = self::sanitize($value);

            if ($sanitized === null && $value !== null) {
                $dropped++;

                continue;
            }

            $clean[$key] = $sanitized;
        }

        if ($dropped > 0) {
            $clean['redacted_keys'] = $dropped;
        }

        return $clean;
    }

    /**
     * @return scalar|list<scalar>|null
     */
    private static function sanitize(mixed $value): string|int|float|bool|array|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        if (is_array($value)) {
            $items = [];

            foreach (array_slice(array_values($value), 0, self::MAX_LIST_ITEMS) as $item) {
                if (is_string($item)) {
                    $items[] = mb_substr($item, 0, self::MAX_VALUE_LENGTH);

                    continue;
                }

                if (is_int($item) || is_float($item) || is_bool($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        // Objekte, Ressourcen und Closures erreichen das Log nie.
        return null;
    }
}
