<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\BoundingBox;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\SchemaViolation;
use App\Services\Ai\Dto\SchemaViolationCode;
use App\Services\Ai\Dto\ValidationOutcome;
use App\Services\Ai\Schemas\FieldNode;
use App\Services\Ai\Schemas\ListNode;
use App\Services\Ai\Schemas\ObjectNode;
use App\Services\Ai\Schemas\SchemaDefinition;
use App\Services\Ai\Schemas\SchemaNode;
use App\Services\Ai\Schemas\ValueKind;

/**
 * Schlanke, eigene Validierung gegen die Schemata dieser Schicht.
 *
 * Bewusst kein zusaetzliches Composer-Paket: die Schemata dieser Schicht
 * verwenden eine klar begrenzte JSON-Schema-Teilmenge, die vollstaendig aus
 * dem Knotenbaum bekannt ist. Eine generische Validierungsbibliothek waere
 * eine weitere Abhaengigkeit ohne fachlichen Mehrwert und koennte die
 * fachlichen Zusatzregeln, insbesondere Integer-Cent und
 * Fundstellenlaenge, ohnehin nicht abbilden.
 *
 * Geprueft werden:
 *
 * - erlaubte Schluessel, additionalProperties ist immer false
 * - Pflichtfelder, alle Schemaschluessel sind Pflichtschluessel
 * - Datentypen und Zulaessigkeit von null
 * - Geldbetraege ausschliesslich als Integer in Cent, ein Float ist eine
 *   Verletzung und wird nicht gerundet (Grundsatz 8)
 * - Datumswerte als gueltiges ISO-8601-Kalenderdatum
 * - Dezimalwerte als Zeichenkette, niemals als binaerer Float
 * - Konfidenz zwischen 0 und 1
 * - Laenge des Fundstellenausschnitts, hoechstens
 *   FieldNode::MAX_SOURCE_EXCERPT_LENGTH Zeichen (Grundsatz 4)
 * - Laengenbegrenzung von Textfeldern und Listen
 *
 * VERBINDLICHE DATENSCHUTZREGEL: Eine Verletzung fuehrt nur Schemapfad, Code
 * und Typangaben mit. Der beanstandete Wert wird niemals uebernommen, weil er
 * Dokumentinhalt ist und in Protokolle, Ausnahmen und Reparaturprompts
 * gelangen wuerde (Abschnitt 13.5).
 */
final class JsonSchemaValidator
{
    /** @var list<SchemaViolation> */
    private array $violations = [];

    /** @var array<string, ExtractedValue> */
    private array $fields = [];

    /**
     * Validiert eine dekodierte Modellantwort gegen ein Schema.
     */
    public function validate(SchemaDefinition $schema, mixed $decoded): ValidationOutcome
    {
        $this->violations = [];
        $this->fields = [];

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->violate('', SchemaViolationCode::KEIN_OBJEKT, 'object', $this->typeName($decoded));

            return ValidationOutcome::invalid($this->violations);
        }

        /** @var array<string, mixed> $decoded */
        $this->walk($schema->root(), $decoded, '');

        if ($this->violations !== []) {
            return ValidationOutcome::invalid($this->violations);
        }

        return ValidationOutcome::valid($decoded, $this->fields);
    }

    /**
     * Validiert eine rohe JSON-Zeichenkette.
     *
     * Die Zeichenkette wird nach dem Dekodieren nicht gespeichert und nicht
     * in eine Verletzung uebernommen.
     */
    public function validateJson(SchemaDefinition $schema, string $json): ValidationOutcome
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ValidationOutcome::invalid([
                new SchemaViolation('', SchemaViolationCode::UNGUELTIGES_JSON, 'object', 'string'),
            ]);
        }

        return $this->validate($schema, $decoded);
    }

    private function walk(SchemaNode $node, mixed $data, string $path): void
    {
        if ($node instanceof ObjectNode) {
            $this->walkObject($node, $data, $path);

            return;
        }

        if ($node instanceof ListNode) {
            $this->walkList($node, $data, $path);

            return;
        }

        if ($node instanceof FieldNode) {
            $this->walkField($node, $data, $path);
        }
    }

    private function walkObject(ObjectNode $node, mixed $data, string $path): void
    {
        if (! is_array($data) || array_is_list($data)) {
            $this->violate($path, SchemaViolationCode::KEIN_OBJEKT, 'object', $this->typeName($data));

            return;
        }

        $children = $node->children();

        foreach (array_keys($data) as $key) {
            if (! array_key_exists((string) $key, $children)) {
                $this->violate($this->join($path, (string) $key), SchemaViolationCode::UNBEKANNTER_SCHLUESSEL);
            }
        }

        foreach ($children as $name => $child) {
            $childPath = $this->join($path, $name);

            if (! array_key_exists($name, $data)) {
                $this->violate($childPath, SchemaViolationCode::PFLICHTFELD_FEHLT);

                continue;
            }

            $this->walk($child, $data[$name], $childPath);
        }
    }

    private function walkList(ListNode $node, mixed $data, string $path): void
    {
        if (! is_array($data) || ! array_is_list($data)) {
            $this->violate($path, SchemaViolationCode::KEINE_LISTE, 'array', $this->typeName($data));

            return;
        }

        if (count($data) > $node->maxItems) {
            $this->violate($path, SchemaViolationCode::LISTE_ZU_LANG, sprintf('<= %d', $node->maxItems), (string) count($data));

            return;
        }

        foreach ($data as $index => $item) {
            $this->walk($node->item, $item, sprintf('%s[%d]', $path, $index));
        }
    }

    private function walkField(FieldNode $node, mixed $data, string $path): void
    {
        if (! is_array($data) || array_is_list($data)) {
            $this->violate($path, SchemaViolationCode::KEIN_OBJEKT, 'object', $this->typeName($data));

            return;
        }

        $allowedKeys = $node->envelopeKeys();

        foreach (array_keys($data) as $key) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                $this->violate($this->join($path, (string) $key), SchemaViolationCode::UNBEKANNTER_SCHLUESSEL);
            }
        }

        $missing = false;

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $data)) {
                $this->violate($this->join($path, $key), SchemaViolationCode::PFLICHTFELD_FEHLT);
                $missing = true;
            }
        }

        if ($missing) {
            return;
        }

        $confidence = $this->validateConfidence($data['confidence'], $this->join($path, 'confidence'));
        $sourcePage = $this->validateSourcePage($data['source_page'], $this->join($path, 'source_page'));
        $sourceExcerpt = $this->validateSourceExcerpt($data['source_excerpt'], $this->join($path, 'source_excerpt'));
        $boundingBox = $node->boundingBoxAllowed
            ? $this->validateBoundingBox($data['bounding_box'], $this->join($path, 'bounding_box'))
            : null;

        $valueValid = $this->validateValue($node, $data['value'], $this->join($path, 'value'));

        if (! $valueValid || $confidence === null) {
            return;
        }

        /** @var string|int|float|bool|null $value */
        $value = $data['value'];

        $this->fields[$path] = new ExtractedValue(
            $path,
            $value,
            $confidence,
            $sourcePage,
            $sourceExcerpt,
            $boundingBox,
        );
    }

    private function validateValue(FieldNode $node, mixed $value, string $path): bool
    {
        if ($value === null) {
            if (! $node->nullable) {
                $this->violate($path, SchemaViolationCode::NULL_NICHT_ZULAESSIG, $node->kind->value, 'null');

                return false;
            }

            return true;
        }

        return match ($node->kind) {
            ValueKind::AMOUNT_CENT => $this->validateAmountCent($value, $path),
            ValueKind::INTEGER => $this->validateInteger($value, $path),
            ValueKind::BOOLEAN => $this->validateBoolean($value, $path),
            ValueKind::ISO_DATE => $this->validateIsoDate($value, $path),
            ValueKind::DECIMAL_STRING => $this->validateDecimalString($value, $path),
            ValueKind::ENUM => $this->validateEnum($node, $value, $path),
            ValueKind::TEXT => $this->validateText($node, $value, $path),
        };
    }

    private function validateAmountCent(mixed $value, string $path): bool
    {
        if (is_int($value)) {
            return true;
        }

        // Ein Dezimalwert oder eine Zeichenkette wird niemals stillschweigend
        // in Cent umgerechnet. Das waere eine stille Annahme (Grundsatz 5)
        // und koennte einen Rundungsfehler in die Abrechnung tragen.
        if (is_float($value) || is_string($value)) {
            $this->violate($path, SchemaViolationCode::BETRAG_NICHT_INTEGER, 'integer', $this->typeName($value));

            return false;
        }

        $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'integer', $this->typeName($value));

        return false;
    }

    private function validateInteger(mixed $value, string $path): bool
    {
        if (is_int($value)) {
            return true;
        }

        $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'integer', $this->typeName($value));

        return false;
    }

    private function validateBoolean(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return true;
        }

        $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'boolean', $this->typeName($value));

        return false;
    }

    private function validateIsoDate(mixed $value, string $path): bool
    {
        if (! is_string($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'string', $this->typeName($value));

            return false;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGES_DATUM, 'JJJJ-MM-TT', 'string');

            return false;
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGES_DATUM, 'gueltiges Kalenderdatum', 'string');

            return false;
        }

        return true;
    }

    private function validateDecimalString(mixed $value, string $path): bool
    {
        if (! is_string($value)) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGER_DEZIMALWERT, 'string', $this->typeName($value));

            return false;
        }

        if (preg_match('/^-?\d{1,15}(\.\d{1,8})?$/', $value) !== 1) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGER_DEZIMALWERT, 'Dezimalzeichenkette', 'string');

            return false;
        }

        return true;
    }

    private function validateEnum(FieldNode $node, mixed $value, string $path): bool
    {
        if (! is_string($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'string', $this->typeName($value));

            return false;
        }

        if (! in_array($value, $node->enumValues ?? [], true)) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGER_AUFZAEHLUNGSWERT, 'Aufzaehlungswert', 'string');

            return false;
        }

        return true;
    }

    private function validateText(FieldNode $node, mixed $value, string $path): bool
    {
        if (! is_string($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'string', $this->typeName($value));

            return false;
        }

        if ($node->maxLength !== null && mb_strlen($value) > $node->maxLength) {
            $this->violate($path, SchemaViolationCode::TEXT_ZU_LANG, sprintf('<= %d Zeichen', $node->maxLength), 'string');

            return false;
        }

        return true;
    }

    private function validateConfidence(mixed $value, string $path): ?float
    {
        if (is_int($value)) {
            $value = (float) $value;
        }

        if (! is_float($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'number', $this->typeName($value));

            return null;
        }

        if (is_nan($value) || is_infinite($value) || $value < 0.0 || $value > 1.0) {
            $this->violate($path, SchemaViolationCode::KONFIDENZ_AUSSERHALB_BEREICH, '0.0 bis 1.0', 'number');

            return null;
        }

        return $value;
    }

    private function validateSourcePage(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'integer oder null', $this->typeName($value));

            return null;
        }

        if ($value < 1) {
            $this->violate($path, SchemaViolationCode::SEITE_AUSSERHALB_BEREICH, '>= 1', 'integer');

            return null;
        }

        return $value;
    }

    private function validateSourceExcerpt(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->violate($path, SchemaViolationCode::FALSCHER_TYP, 'string oder null', $this->typeName($value));

            return null;
        }

        if (mb_strlen($value) > FieldNode::MAX_SOURCE_EXCERPT_LENGTH) {
            $this->violate(
                $path,
                SchemaViolationCode::FUNDSTELLE_ZU_LANG,
                sprintf('<= %d Zeichen', FieldNode::MAX_SOURCE_EXCERPT_LENGTH),
                'string',
            );

            return null;
        }

        return $value;
    }

    private function validateBoundingBox(mixed $value, string $path): ?BoundingBox
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGE_BOUNDING_BOX, 'object oder null', $this->typeName($value));

            return null;
        }

        foreach (['page', 'x', 'y', 'width', 'height'] as $key) {
            if (! array_key_exists($key, $value) || ! is_numeric($value[$key])) {
                $this->violate($path, SchemaViolationCode::UNGUELTIGE_BOUNDING_BOX, 'page, x, y, width, height', 'object');

                return null;
            }
        }

        $page = (int) $value['page'];

        if ($page < 1) {
            $this->violate($path, SchemaViolationCode::UNGUELTIGE_BOUNDING_BOX, 'page >= 1', 'integer');

            return null;
        }

        return new BoundingBox(
            $page,
            (float) $value['x'],
            (float) $value['y'],
            (float) $value['width'],
            (float) $value['height'],
        );
    }

    private function violate(
        string $path,
        SchemaViolationCode $code,
        ?string $expected = null,
        ?string $actual = null,
    ): void {
        $this->violations[] = new SchemaViolation($path, $code, $expected, $actual);
    }

    private function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'.'.$key;
    }

    /**
     * Typname des gelieferten Wertes. Bewusst nur der Typ, niemals der Wert.
     */
    private function typeName(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }
}
