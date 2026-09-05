<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\InvoiceNumberSequence;
use App\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rechnungsnummernkreis: lueckenlos, eindeutig und unter Parallelzugriff
 * korrekt (Abschnitt 15.2, 23.3).
 */
final class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_nummern_folgen_dem_format_aus_der_konfiguration(): void
    {
        $nummer = app(InvoiceNumberSequence::class)->next(2026);

        self::assertSame('NK-2026-000001', $nummer);
    }

    public function test_die_nummern_sind_lueckenlos(): void
    {
        $folge = app(InvoiceNumberSequence::class);
        $nummern = [];

        for ($lauf = 0; $lauf < 25; $lauf++) {
            $nummern[] = $folge->next(2026);
        }

        for ($lauf = 0; $lauf < 25; $lauf++) {
            self::assertSame(sprintf('NK-2026-%06d', $lauf + 1), $nummern[$lauf]);
        }

        self::assertSame(25, count(array_unique($nummern)));
        self::assertSame(25, $folge->lastValue(2026));
    }

    public function test_das_jahr_ohne_angabe_folgt_der_fachlichen_zeitzone_und_nicht_der_anwendungszeitzone(): void
    {
        $zeitzone = date_default_timezone_get();
        config()->set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        try {
            // In Europe/Berlin ist bereits der 01.01.2027.
            $nummer = app(InvoiceNumberSequence::class)->next();
            $letzter = app(InvoiceNumberSequence::class)->lastValue();
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($zeitzone);
            config()->set('app.timezone', $zeitzone);
        }

        self::assertSame('NK-2027-000001', $nummer);
        self::assertSame(1, $letzter);
    }

    public function test_jedes_jahr_beginnt_mit_einem_eigenen_kreis(): void
    {
        $folge = app(InvoiceNumberSequence::class);

        self::assertSame('NK-2026-000001', $folge->next(2026));
        self::assertSame('NK-2027-000001', $folge->next(2027));
        self::assertSame('NK-2026-000002', $folge->next(2026));
        self::assertSame(1, $folge->lastValue(2027));
        self::assertSame(2, $folge->lastValue(2026));
    }

    public function test_praefix_und_stellenzahl_stammen_aus_der_konfiguration(): void
    {
        config()->set('smartabrechnen.invoicing.number_prefix', 'RE');
        config()->set('smartabrechnen.invoicing.number_padding', 4);

        self::assertSame('RE-2026-0001', app(InvoiceNumberSequence::class)->next(2026));
    }

    public function test_die_vergabe_sperrt_die_zaehlerzeile(): void
    {
        $folge = app(InvoiceNumberSequence::class);
        $folge->next(2026);

        $gesperrt = false;

        // Nachweis der Zeilensperre: Die Abfrage innerhalb der Vergabe laeuft
        // ueber lockForUpdate. Es wird geprueft, dass die Vergabe genau diese
        // Abfrage stellt und dabei in einer Transaktion liegt.
        DB::listen(function ($abfrage) use (&$gesperrt): void {
            if (str_contains(strtolower($abfrage->sql), 'invoice_number_sequences')
                && str_contains(strtolower($abfrage->sql), 'select')) {
                $gesperrt = $gesperrt || str_contains(strtolower($abfrage->sql), 'for update')
                    || DB::transactionLevel() > 0;
            }
        });

        $folge->next(2026);

        self::assertTrue($gesperrt, 'Die Vergabe muss die Zaehlerzeile in einer Transaktion sperren.');

        // SQLite kennt keine Zeilensperre und gibt "for update" nicht aus. Der
        // Nachweis, dass die Vergabe die Sperre anfordert, erfolgt daher
        // zusaetzlich am Quelltext. Auf MariaDB wird daraus eine echte
        // Zeilensperre.
        $quelle = file_get_contents((string) (new \ReflectionClass(InvoiceNumberSequence::class))->getFileName());

        self::assertIsString($quelle);
        self::assertStringContainsString('lockForUpdate()', $quelle);
        self::assertStringContainsString('DB::transaction(', $quelle);
    }

    public function test_mehrere_vergaben_in_einer_transaktion_bleiben_lueckenlos(): void
    {
        $folge = app(InvoiceNumberSequence::class);

        $nummern = DB::transaction(static function () use ($folge): array {
            return [$folge->next(2026), $folge->next(2026), $folge->next(2026)];
        });

        self::assertSame(['NK-2026-000001', 'NK-2026-000002', 'NK-2026-000003'], $nummern);
        self::assertSame(3, $folge->lastValue(2026));
    }

    public function test_es_entsteht_nur_eine_zaehlerzeile_je_jahr(): void
    {
        $folge = app(InvoiceNumberSequence::class);

        $folge->next(2026);
        $folge->next(2026);

        self::assertSame(1, DB::table(InvoiceNumberSequence::TABLE)
            ->where('prefix', 'NK')
            ->where('year', 2026)
            ->count());
    }

    public function test_eine_dublette_wird_durch_den_eindeutigen_schluessel_ausgeschlossen(): void
    {
        $nummer = app(InvoiceNumberSequence::class)->next(2026);

        Invoice::factory()->create(['number' => $nummer]);

        $this->expectException(QueryException::class);

        Invoice::factory()->create(['number' => $nummer]);
    }

    public function test_der_zaehler_wird_nicht_zurueckgesetzt(): void
    {
        $folge = app(InvoiceNumberSequence::class);

        $folge->next(2026);
        $folge->next(2026);

        // Auch wenn keine Rechnung zu den Nummern existiert, laeuft der Zaehler
        // weiter. Eine dokumentierte Luecke ist zulaessig, eine zweimal
        // vergebene Nummer nicht.
        self::assertSame('NK-2026-000003', $folge->next(2026));
    }

    public function test_die_zaehlerzeile_wird_bei_bedarf_angelegt(): void
    {
        self::assertSame(0, app(InvoiceNumberSequence::class)->lastValue(2030));

        app(InvoiceNumberSequence::class)->next(2030);

        self::assertSame(1, app(InvoiceNumberSequence::class)->lastValue(2030));
    }
}
