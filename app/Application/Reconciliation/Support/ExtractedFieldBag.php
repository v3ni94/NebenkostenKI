<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Support;

use App\Models\Document;
use App\Models\ExtractedField;
use Illuminate\Support\Carbon;

/**
 * Lesezugriff auf die dauerhaft gespeicherten Inhaltsdaten eines Dokuments.
 *
 * Die Klasse liest ausschliesslich aus extracted_fields. Sie schaetzt nichts
 * und leitet keinen Wert ab. Fehlt ein Wert, gibt sie null zurueck; die
 * aufrufende Schicht erzeugt daraus eine Pruefaufgabe (Grundsatz 5).
 *
 * Die Schluessel sind die Schemapfade der KI-Schicht, zum Beispiel
 * "aussteller" oder "positionen[0].betrag_brutto_cent".
 *
 * Der gespeicherte Wert liegt in einer Huelle. Der Regelfall ist
 * ['wert' => ...]; aeltere oder abweichend geschriebene Datensaetze werden
 * ueber den ersten skalaren Eintrag gelesen, damit kein Wert verloren geht.
 */
final class ExtractedFieldBag
{
    /**
     * @param  array<string, ExtractedField>  $fields
     */
    private function __construct(private readonly array $fields) {}

    public static function forDocument(Document $document): self
    {
        $records = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->orderBy('schema_key')
            ->get();

        $indexed = [];

        foreach ($records as $record) {
            $key = $record->getAttribute('schema_key');

            if (is_string($key) && $key !== '') {
                $indexed[$key] = $record;
            }
        }

        return new self($indexed);
    }

    /**
     * @param  array<string, ExtractedField>  $fields
     */
    public static function fromRecords(array $fields): self
    {
        return new self($fields);
    }

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->fields);
    }

    public function field(string $path): ?ExtractedField
    {
        return $this->fields[$path] ?? null;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Der maßgebliche Wert. Eine Nutzerkorrektur hat Vorrang vor dem
     * maschinellen Wert.
     */
    public function raw(string $path): string|int|float|bool|null
    {
        $record = $this->fields[$path] ?? null;

        if (! $record instanceof ExtractedField) {
            return null;
        }

        $corrected = $record->getAttribute('corrected_value');

        if (is_array($corrected)) {
            $value = $this->unwrap($corrected);

            if ($value !== null) {
                return $value;
            }
        }

        $value = $record->getAttribute('value');

        return is_array($value) ? $this->unwrap($value) : null;
    }

    public function text(string $path, int $maxLength = 190): ?string
    {
        $value = $this->raw($path);

        if (is_bool($value) || $value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    public function integer(string $path): ?int
    {
        $value = $this->raw($path);

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    public function boolean(string $path): ?bool
    {
        $value = $this->raw($path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (mb_strtolower(trim($value))) {
                'true', 'ja', '1' => true,
                'false', 'nein', '0' => false,
                default => null,
            };
        }

        return null;
    }

    public function date(string $path): ?Carbon
    {
        $value = $this->text($path, 40);

        if ($value === null) {
            return null;
        }

        $parsed = Carbon::hasFormat($value, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $value)
            : null;

        return $parsed instanceof Carbon ? $parsed->startOfDay() : null;
    }

    public function confidence(string $path): ?string
    {
        $record = $this->fields[$path] ?? null;
        $value = $record?->getAttribute('confidence');

        return is_string($value) ? $value : null;
    }

    public function page(string $path): ?int
    {
        $record = $this->fields[$path] ?? null;
        $value = $record?->getAttribute('page_number');

        return is_int($value) ? $value : null;
    }

    public function excerpt(string $path): ?string
    {
        $record = $this->fields[$path] ?? null;
        $value = $record?->getAttribute('source_excerpt');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Indizes einer Liste, zum Beispiel "positionen" oder "kostenarten".
     *
     * @return list<int>
     */
    public function listIndexes(string $listPath): array
    {
        $indexes = [];
        $pattern = '/^'.preg_quote($listPath, '/').'\[(\d+)\]\./';

        foreach (array_keys($this->fields) as $path) {
            if (preg_match($pattern, $path, $treffer) === 1) {
                $indexes[(int) $treffer[1]] = true;
            }
        }

        $list = array_keys($indexes);
        sort($list);

        return array_values($list);
    }

    /**
     * Die niedrigste Konfidenz der angegebenen Pfade. Sie bestimmt die
     * Konfidenz der daraus erzeugten Kostenposition.
     */
    public function lowestConfidence(string ...$paths): ?string
    {
        $lowest = null;

        foreach ($paths as $path) {
            $value = $this->confidence($path);

            if ($value === null) {
                continue;
            }

            if ($lowest === null || (float) $value < (float) $lowest) {
                $lowest = $value;
            }
        }

        return $lowest;
    }

    /**
     * Erster Pfad mit gespeichertem Seitenbezug.
     */
    public function firstPage(string ...$paths): ?int
    {
        foreach ($paths as $path) {
            $page = $this->page($path);

            if ($page !== null) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Erster gespeicherter Fundstellenausschnitt.
     */
    public function firstExcerpt(string ...$paths): ?string
    {
        foreach ($paths as $path) {
            $excerpt = $this->excerpt($path);

            if ($excerpt !== null) {
                return $excerpt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function unwrap(array $envelope): string|int|float|bool|null
    {
        if (array_key_exists('wert', $envelope)) {
            $value = $envelope['wert'];

            return is_scalar($value) ? $value : null;
        }

        foreach ($envelope as $value) {
            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }
}
