<?php

declare(strict_types=1);

namespace Tests\Unit\Reconciliation;

use App\Application\Reconciliation\CategoryResolver;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Models\ExtractedField;
use Tests\TestCase;

/**
 * Lesen der ausgelesenen Inhaltsdaten ohne Datenbank.
 *
 * Der Bag schaetzt nichts. Ein fehlender oder unlesbarer Wert bleibt null.
 */
final class ExtractedFieldBagTest extends TestCase
{
    public function test_werte_werden_typsicher_gelesen(): void
    {
        $bag = $this->bag([
            'aussteller' => 'Gartenbau Beispiel',
            'gesamtbetrag_brutto_cent' => 48000,
            'kostenaufschluesselung_vorhanden' => true,
            'belegdatum' => '2025-06-30',
        ]);

        self::assertSame('Gartenbau Beispiel', $bag->text('aussteller'));
        self::assertSame(48000, $bag->integer('gesamtbetrag_brutto_cent'));
        self::assertTrue($bag->boolean('kostenaufschluesselung_vorhanden'));
        self::assertSame('30.06.2025', $bag->date('belegdatum')?->format('d.m.Y'));
    }

    public function test_fehlende_werte_bleiben_null(): void
    {
        $bag = $this->bag([
            'aussteller' => null,
            'gesamtbetrag_brutto_cent' => null,
        ]);

        self::assertNull($bag->text('aussteller'));
        self::assertNull($bag->integer('gesamtbetrag_brutto_cent'));
        self::assertNull($bag->date('belegdatum'));
        self::assertFalse($bag->has('unbekannter_pfad'));
    }

    public function test_unlesbares_datum_wird_nicht_geraten(): void
    {
        $bag = $this->bag(['belegdatum' => '30.06.2025']);

        self::assertNull($bag->date('belegdatum'));
    }

    public function test_nutzerkorrektur_hat_vorrang(): void
    {
        $feld = new ExtractedField;
        $feld->forceFill([
            'schema_key' => 'gesamtbetrag_brutto_cent',
            'value' => ['wert' => 48000],
            'corrected_value' => ['wert' => 50000],
            'confidence' => '0.6000',
            'page_number' => 2,
            'source_excerpt' => 'Gesamtbetrag 500,00 EUR',
        ]);

        $bag = ExtractedFieldBag::fromRecords(['gesamtbetrag_brutto_cent' => $feld]);

        self::assertSame(50000, $bag->integer('gesamtbetrag_brutto_cent'));
        self::assertSame(2, $bag->page('gesamtbetrag_brutto_cent'));
        self::assertSame('Gesamtbetrag 500,00 EUR', $bag->excerpt('gesamtbetrag_brutto_cent'));
    }

    public function test_listenindizes_werden_sortiert_geliefert(): void
    {
        $bag = $this->bag([
            'positionen[2].bezeichnung' => 'Dritte',
            'positionen[0].bezeichnung' => 'Erste',
            'positionen[1].bezeichnung' => 'Zweite',
            'einheiten[0].summe_cent' => 1000,
        ]);

        self::assertSame([0, 1, 2], $bag->listIndexes('positionen'));
        self::assertSame([0], $bag->listIndexes('einheiten'));
        self::assertSame([], $bag->listIndexes('kostenarten'));
    }

    public function test_niedrigste_konfidenz_bestimmt_die_position(): void
    {
        $hoch = new ExtractedField;
        $hoch->forceFill(['schema_key' => 'a', 'value' => ['wert' => 1], 'confidence' => '0.9800', 'page_number' => 1]);

        $niedrig = new ExtractedField;
        $niedrig->forceFill(['schema_key' => 'b', 'value' => ['wert' => 2], 'confidence' => '0.5100', 'page_number' => 3]);

        $bag = ExtractedFieldBag::fromRecords(['a' => $hoch, 'b' => $niedrig]);

        self::assertSame('0.5100', $bag->lowestConfidence('a', 'b'));
        self::assertSame(1, $bag->firstPage('a', 'b'));
    }

    public function test_kategorievorschlag_nur_bei_eindeutigem_treffer(): void
    {
        $resolver = new CategoryResolver;

        self::assertSame('GARTENPFLEGE', $resolver->proposeCode('Gartenpflege Frühjahr'));
        self::assertSame('MUELLBESEITIGUNG', $resolver->proposeCode('Müllgebühr 2025'));
        self::assertNull($resolver->proposeCode('Heizung und Warmwasser'));
        self::assertNull($resolver->proposeCode('Diverse Leistungen'));
        self::assertNull($resolver->proposeCode(null));
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $werte
     */
    private function bag(array $werte): ExtractedFieldBag
    {
        $felder = [];

        foreach ($werte as $pfad => $wert) {
            $feld = new ExtractedField;
            $feld->forceFill([
                'schema_key' => $pfad,
                'value' => ['wert' => $wert],
                'confidence' => '0.9500',
                'page_number' => 1,
                'source_excerpt' => 'Fundstelle',
            ]);

            $felder[$pfad] = $feld;
        }

        return ExtractedFieldBag::fromRecords($felder);
    }
}
