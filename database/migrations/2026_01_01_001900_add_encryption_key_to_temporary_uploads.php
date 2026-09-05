<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Umhuellter Dateischluessel je Upload
|--------------------------------------------------------------------------
|
| Jede Datei im Kurzzeitbereich ist mit einem zufaelligen Dateischluessel
| verschluesselt (Masterprompt Abschnitt 3.4 und 6.3 Schritt 1). Diese Spalte
| traegt den Dateischluessel ausschliesslich in der mit einem aus APP_KEY
| abgeleiteten Hauptschluessel umhuellten Form. Der Klartextschluessel wird
| niemals gespeichert.
|
| Nullable, weil Tombstones nach der Loeschung keinen Schluessel mehr tragen
| und weil die Spalte an bestehenden Datensaetzen nachgeruestet wird. Ein
| Datensatz ohne Schluessel kann seine Datei nicht mehr lesen; die Datei
| wird ueber TTL und Cleanup entfernt.
|
| Die Spalte ist treiberneutral (MariaDB, MySQL, SQLite) und wird nicht
| indexiert.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_uploads', function (Blueprint $table): void {
            $table->string('encryption_key_wrapped', 255)->nullable()
                ->comment('Mit dem Anwendungsschluessel umhuellter Dateischluessel, niemals im Klartext');
        });
    }

    public function down(): void
    {
        Schema::table('temporary_uploads', function (Blueprint $table): void {
            $table->dropColumn('encryption_key_wrapped');
        });
    }
};
