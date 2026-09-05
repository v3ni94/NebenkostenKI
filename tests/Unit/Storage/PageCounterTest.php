<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\FileCategory;
use App\Services\Storage\PageCounter;
use Tests\TestCase;

/**
 * Prueft die Bestimmung der Seitenzahl.
 *
 * Grundsatz 5: Ist die Seitenzahl nicht sicher bestimmbar, bleibt sie null. Es
 * wird niemals geschaetzt.
 */
class PageCounterTest extends TestCase
{
    public function test_zaehlt_die_seiten_eines_pdf(): void
    {
        $counter = new PageCounter;

        $this->assertSame(1, $counter->count(
            SampleFiles::write(SampleFiles::pdf(1), 'pdf'),
            FileCategory::PDF,
            'application/pdf'
        ));

        $this->assertSame(7, $counter->count(
            SampleFiles::write(SampleFiles::pdf(7), 'pdf'),
            FileCategory::PDF,
            'application/pdf'
        ));
    }

    public function test_bild_hat_immer_eine_seite(): void
    {
        $counter = new PageCounter;

        $this->assertSame(1, $counter->count(
            SampleFiles::write(SampleFiles::png(), 'png'),
            FileCategory::BILD,
            'image/png'
        ));
    }

    public function test_zaehlt_die_arbeitsblaetter_einer_xlsx_datei(): void
    {
        $counter = new PageCounter;

        $this->assertSame(3, $counter->count(
            SampleFiles::write(SampleFiles::xlsx(3), 'xlsx'),
            FileCategory::TABELLE,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ));
    }

    public function test_gibt_null_zurueck_wenn_die_seitenzahl_nicht_bestimmbar_ist(): void
    {
        $counter = new PageCounter;

        $this->assertNull($counter->count(
            SampleFiles::write('%PDF-1.4 ohne Seitenobjekte', 'pdf'),
            FileCategory::PDF,
            'application/pdf'
        ));

        $this->assertNull($counter->count(
            SampleFiles::write(SampleFiles::zip(['a.pdf' => SampleFiles::pdf()]), 'zip'),
            FileCategory::ARCHIV,
            'application/zip'
        ));
    }
}
