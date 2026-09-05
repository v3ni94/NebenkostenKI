<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Nummernkreis der Leistungsrechnungen (Abschnitt 15.2)
|------------------------------------------------------------------------------
|
| BEGRUENDUNG DIESER TABELLE
|
| Geprueft wurde zuerst, ob die Tabelle invoices allein genuegt, also ein
| "SELECT MAX(number) ... FOR UPDATE" auf den bereits vorhandenen Rechnungen des
| Jahres. Das genuegt NICHT:
|
|  1. Beim ersten Beleg eines Jahres existiert keine Zeile, die gesperrt werden
|     koennte. Eine Sperre auf eine leere Ergebnismenge ist keine Zeilensperre,
|     sondern haengt vollstaendig am Sperrverhalten der jeweiligen Datenbank.
|     InnoDB setzt in diesem Fall eine Next-Key- beziehungsweise Gap-Sperre,
|     SQLite kennt beides nicht. Ein Verhalten, das nur auf einer Engine
|     zufaellig richtig ist, ist fuer einen lueckenlosen Nummernkreis zu wenig.
|  2. Der hoechste vergebene Wert muesste aus einer Zeichenkette zurueckgerechnet
|     werden. Aendert sich das Praefix oder die Stellenzahl, wird aus einem
|     Sortierproblem eine Luecke oder eine Dublette.
|
| Deshalb fuehrt der Nummernkreis genau eine Zaehlerzeile je Praefix und Jahr.
| Die Vergabe sperrt diese vorhandene Zeile mit lockForUpdate() und erhoeht sie
| in derselben Transaktion. Damit ist die Vergabe auf jeder unterstuetzten
| Datenbank eine echte Zeilensperre. Der eindeutige Schluessel auf
| invoices.number bleibt als zweite, unabhaengige Sicherung bestehen: selbst ein
| Programmierfehler kann keine Dublette festschreiben.
|
| Der Zaehler wird niemals verringert und niemals zurueckgesetzt. Eine Korrektur
| erfolgt ausschliesslich ueber eine Stornorechnung mit eigenem Beleg, die
| ebenfalls eine neue Nummer aus diesem Kreis erhaelt.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('prefix', 16)
                ->comment('Praefix aus config smartabrechnen.invoicing.number_prefix, Beispiel NK');
            $table->unsignedSmallInteger('year')
                ->comment('Kalenderjahr des Rechnungsdatums');
            $table->unsignedBigInteger('last_value')->default(0)
                ->comment('Letzte vergebene laufende Nummer, wird nie verringert');

            $table->timestamps();

            // Genau eine Zaehlerzeile je Praefix und Jahr. Der eindeutige
            // Schluessel verhindert, dass zwei gleichzeitige Anlagen zwei
            // Zaehler und damit zwei Nummernkreise erzeugen.
            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
