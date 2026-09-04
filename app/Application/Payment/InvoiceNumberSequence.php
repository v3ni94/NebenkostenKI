<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Support\BusinessTimezone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lueckenloser Rechnungsnummernkreis (Abschnitt 15.2).
 *
 * FORMAT: NK-2026-000001, zusammengesetzt aus
 *   config('smartabrechnen.invoicing.number_prefix')  Standard NK
 *   dem Kalenderjahr des Rechnungsdatums in der fachlichen Zeitzone
 *   (App\Support\BusinessTimezone, Europe/Berlin, ADR-018)
 *   der laufenden Nummer mit number_padding Stellen, Standard 6
 *
 * VERGABE, verbindlich:
 *
 *  1. Die Vergabe laeuft in einer Transaktion. Die Zaehlerzeile des Jahres wird
 *     mit lockForUpdate() gesperrt, erhoeht und geschrieben. Ein zweiter
 *     Vorgang wartet an dieser Sperre und erhaelt danach den naechsten Wert.
 *     Es entsteht damit weder eine Luecke noch eine Dublette.
 *  2. Existiert die Zaehlerzeile noch nicht, wird sie angelegt. Der eindeutige
 *     Schluessel auf (prefix, year) verhindert, dass zwei gleichzeitige Anlagen
 *     zwei Zaehler erzeugen; der Verlierer liest die vorhandene Zeile.
 *  3. Der Zaehler wird niemals verringert und niemals zurueckgesetzt. Auch eine
 *     Stornorechnung verbraucht eine eigene Nummer aus diesem Kreis.
 *  4. Die vergebene Nummer wird niemals wiederverwendet, auch dann nicht, wenn
 *     die aufrufende Transaktion spaeter scheitert. Eine Luecke im Beleg ist
 *     dokumentierbar; eine zweimal vergebene Nummer waere ein Fehler in der
 *     Rechnungslegung. Der eindeutige Schluessel auf invoices.number sichert
 *     dies zusaetzlich unabhaengig von dieser Klasse.
 *
 * Die Begruendung fuer die eigene Zaehlertabelle steht in der Migration
 * 2026_01_01_001600_create_invoice_number_sequences_table.php.
 */
final class InvoiceNumberSequence
{
    public const string TABLE = 'invoice_number_sequences';

    /**
     * Vergibt die naechste Rechnungsnummer des Jahres.
     */
    public function next(?int $year = null): string
    {
        $year ??= BusinessTimezone::currentYear();
        $prefix = $this->prefix();

        $value = DB::transaction(function () use ($prefix, $year): int {
            $row = DB::table(self::TABLE)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = $this->createCounter($prefix, $year);
            }

            $last = is_object($row) && property_exists($row, 'last_value') ? $row->last_value : 0;
            $next = (is_numeric($last) ? (int) $last : 0) + 1;

            DB::table(self::TABLE)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return $this->format($prefix, $year, $value);
    }

    /**
     * Letzter vergebener Wert des Jahres, ohne zu vergeben. Nur fuer Anzeige
     * und Pruefung.
     */
    public function lastValue(?int $year = null): int
    {
        $year ??= BusinessTimezone::currentYear();

        $value = DB::table(self::TABLE)
            ->where('prefix', $this->prefix())
            ->where('year', $year)
            ->value('last_value');

        return is_numeric($value) ? (int) $value : 0;
    }

    public function format(string $prefix, int $year, int $value): string
    {
        return sprintf('%s-%04d-%s', $prefix, $year, str_pad((string) $value, $this->padding(), '0', STR_PAD_LEFT));
    }

    /**
     * Legt die Zaehlerzeile des Jahres an. Bei einem gleichzeitigen zweiten
     * Versuch gewinnt genau eine Anlage; der Verlierer liest die vorhandene
     * Zeile erneut mit Sperre.
     */
    private function createCounter(string $prefix, int $year): object
    {
        try {
            DB::table(self::TABLE)->insert([
                'id' => (string) Str::ulid(),
                'prefix' => $prefix,
                'year' => $year,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Der eindeutige Schluessel hat zugeschlagen: die Zeile existiert
            // inzwischen. Sie wird unten mit Sperre gelesen.
        }

        $row = DB::table(self::TABLE)
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                'Der Rechnungsnummernkreis konnte nicht angelegt werden. Es wird keine Rechnung ohne '
                .'lückenlose Nummer erzeugt.'
            );
        }

        return $row;
    }

    private function prefix(): string
    {
        $value = config('smartabrechnen.invoicing.number_prefix');

        return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : 'NK';
    }

    private function padding(): int
    {
        $value = config('smartabrechnen.invoicing.number_padding');

        return is_int($value) && $value >= 1 && $value <= 12 ? $value : 6;
    }
}
