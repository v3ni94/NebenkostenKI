<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Dto\SchemaViolationCode;
use App\Services\Ai\JsonSchemaValidator;
use App\Services\Ai\Schemas\FieldNode;
use App\Services\Ai\Schemas\ObjectNode;
use App\Services\Ai\Schemas\SchemaDefinition;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;

/**
 * Schemavalidierung der KI-Schicht.
 *
 * Alle Beispielwerte sind frei erfunden.
 */
final class JsonSchemaValidatorTest extends TestCase
{
    private JsonSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new JsonSchemaValidator;
    }

    public function test_gueltige_antwort_wird_akzeptiert(): void
    {
        $outcome = $this->validator->validate($this->schema(), $this->validPayload());

        self::assertTrue($outcome->isValid());
        self::assertSame([], $outcome->violations());
        self::assertSame(214242, $outcome->data()['betrag_cent']['value']);
        self::assertCount(5, $outcome->fields());
    }

    public function test_gueltige_fixture_hausgeldabrechnung_wird_akzeptiert(): void
    {
        $schema = AiTestFactory::schemas()->get('hausgeldabrechnung');
        $outcome = $this->validator->validateJson($schema, AiTestFactory::fixture('hausgeldabrechnung.json'));

        self::assertTrue($outcome->isValid(), implode("\n", array_map(
            static fn ($violation): string => $violation->describe(),
            $outcome->violations(),
        )));
    }

    public function test_fehlendes_pflichtfeld_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        unset($payload['belegdatum']);

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::PFLICHTFELD_FEHLT->value, $outcome->violationCodes());
        self::assertContains('belegdatum', $outcome->violationPaths());
    }

    public function test_unbekannter_schluessel_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['nicht_im_schema'] = ['value' => 'x'];

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNBEKANNTER_SCHLUESSEL->value, $outcome->violationCodes());
    }

    public function test_falscher_typ_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['bezeichnung']['value'] = 42;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::FALSCHER_TYP->value, $outcome->violationCodes());
        self::assertContains('bezeichnung.value', $outcome->violationPaths());
    }

    public function test_float_statt_integer_cent_wird_gemeldet_und_nicht_gerundet(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['value'] = 2142.42;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::BETRAG_NICHT_INTEGER->value, $outcome->violationCodes());
        self::assertSame([], $outcome->data(), 'Ein unzulaessiger Betrag darf nicht uebernommen werden.');
    }

    public function test_zeichenkette_statt_integer_cent_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['value'] = '2142,42 EUR';

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::BETRAG_NICHT_INTEGER->value, $outcome->violationCodes());
    }

    public function test_ungueltiges_datum_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['belegdatum']['value'] = '15.12.2025';

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNGUELTIGES_DATUM->value, $outcome->violationCodes());
    }

    public function test_nicht_existierendes_kalenderdatum_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['belegdatum']['value'] = '2025-02-30';

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNGUELTIGES_DATUM->value, $outcome->violationCodes());
    }

    public function test_konfidenz_oberhalb_eins_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['confidence'] = 1.4;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::KONFIDENZ_AUSSERHALB_BEREICH->value, $outcome->violationCodes());
    }

    public function test_negative_konfidenz_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['confidence'] = -0.1;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::KONFIDENZ_AUSSERHALB_BEREICH->value, $outcome->violationCodes());
    }

    public function test_zu_langer_source_excerpt_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['bezeichnung']['source_excerpt'] = str_repeat('A', FieldNode::MAX_SOURCE_EXCERPT_LENGTH + 1);

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::FUNDSTELLE_ZU_LANG->value, $outcome->violationCodes());
    }

    public function test_source_excerpt_an_der_grenze_wird_akzeptiert(): void
    {
        $payload = $this->validPayload();
        $payload['bezeichnung']['source_excerpt'] = str_repeat('A', FieldNode::MAX_SOURCE_EXCERPT_LENGTH);

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertTrue($outcome->isValid());
    }

    public function test_seitenzahl_null_ist_unzulaessig(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['source_page'] = 0;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::SEITE_AUSSERHALB_BEREICH->value, $outcome->violationCodes());
    }

    public function test_null_ist_fuer_fehlende_werte_zulaessig(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent'] = [
            'value' => null,
            'confidence' => 0.0,
            'source_page' => null,
            'source_excerpt' => null,
            'bounding_box' => null,
        ];

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertTrue($outcome->isValid());
        self::assertTrue($outcome->fields()['betrag_cent']->isMissing());
    }

    public function test_ungueltiger_aufzaehlungswert_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['belegart']['value'] = 'PHANTASIEWERT';

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNGUELTIGER_AUFZAEHLUNGSWERT->value, $outcome->violationCodes());
    }

    public function test_ungueltiger_dezimalwert_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['flaeche_qm']['value'] = '72,40';

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNGUELTIGER_DEZIMALWERT->value, $outcome->violationCodes());
    }

    public function test_dezimalwert_als_float_wird_gemeldet(): void
    {
        $payload = $this->validPayload();
        $payload['flaeche_qm']['value'] = 72.4;

        $outcome = $this->validator->validate($this->schema(), $payload);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::UNGUELTIGER_DEZIMALWERT->value, $outcome->violationCodes());
    }

    public function test_ungueltiges_json_wird_gemeldet(): void
    {
        $outcome = $this->validator->validateJson($this->schema(), 'das ist kein JSON');

        self::assertFalse($outcome->isValid());
        self::assertSame([SchemaViolationCode::UNGUELTIGES_JSON->value], $outcome->violationCodes());
    }

    public function test_liste_statt_objekt_wird_gemeldet(): void
    {
        $outcome = $this->validator->validate($this->schema(), [1, 2, 3]);

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::KEIN_OBJEKT->value, $outcome->violationCodes());
    }

    public function test_reparaturhinweis_enthaelt_keinen_beanstandeten_wert(): void
    {
        $payload = $this->validPayload();
        $payload['betrag_cent']['value'] = 2142.42;
        $payload['bezeichnung']['value'] = 'Streng geheime Fundstelle aus dem Dokument';

        $outcome = $this->validator->validate($this->schema(), $payload);
        $instruction = $outcome->repairInstruction();

        self::assertFalse($outcome->isValid());
        self::assertStringNotContainsString('2142.42', $instruction);
        self::assertStringNotContainsString('Streng geheime Fundstelle', $instruction);
        self::assertStringContainsString('betrag_cent.value', $instruction);
        self::assertStringContainsString('Integer in Cent', $instruction);
    }

    public function test_fixture_mit_dezimalbetrag_ist_nicht_schemakonform(): void
    {
        $schema = AiTestFactory::schemas()->get('rechnung_bescheid');
        $outcome = $this->validator->validateJson(
            $schema,
            AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json'),
        );

        self::assertFalse($outcome->isValid());
        self::assertContains(SchemaViolationCode::BETRAG_NICHT_INTEGER->value, $outcome->violationCodes());
    }

    public function test_alle_registrierten_fixtures_sind_schemakonform(): void
    {
        $registry = AiTestFactory::schemas();

        foreach ($registry->keys() as $key) {
            $path = AiTestFactory::fixtureDirectory().'/'.$key.'.json';

            self::assertFileExists($path, sprintf('Fixture fuer Schema "%s" fehlt.', $key));

            $outcome = $this->validator->validateJson($registry->get($key), (string) file_get_contents($path));

            self::assertTrue($outcome->isValid(), sprintf(
                'Fixture "%s" ist nicht schemakonform: %s',
                $key,
                implode(' | ', $outcome->violationPaths()),
            ));
        }
    }

    /**
     * Kleines, vollstaendig kontrolliertes Testschema.
     */
    private function schema(): SchemaDefinition
    {
        $root = ObjectNode::make('Testschema')
            ->field('belegart', FieldNode::enumOf('Belegart', ['RECHNUNG', 'GUTSCHRIFT']))
            ->field('bezeichnung', FieldNode::text('Bezeichnung', 200))
            ->field('belegdatum', FieldNode::isoDate('Belegdatum'))
            ->field('betrag_cent', FieldNode::amountCent('Betrag in Cent', true))
            ->field('flaeche_qm', FieldNode::decimal('Flaeche'));

        return new SchemaDefinition('testschema', '1.0.0', $root);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'belegart' => [
                'value' => 'RECHNUNG',
                'confidence' => 0.98,
                'source_page' => 1,
                'source_excerpt' => 'Rechnung',
            ],
            'bezeichnung' => [
                'value' => 'Hausmeisterleistungen',
                'confidence' => 0.94,
                'source_page' => 1,
                'source_excerpt' => 'Hausmeisterleistungen',
            ],
            'belegdatum' => [
                'value' => '2025-12-15',
                'confidence' => 0.97,
                'source_page' => 1,
                'source_excerpt' => '15.12.2025',
            ],
            'betrag_cent' => [
                'value' => 214242,
                'confidence' => 0.96,
                'source_page' => 1,
                'source_excerpt' => '2.142,42 EUR',
                'bounding_box' => null,
            ],
            'flaeche_qm' => [
                'value' => '72.40',
                'confidence' => 0.9,
                'source_page' => 1,
                'source_excerpt' => '72,40 qm',
            ],
        ];
    }
}
