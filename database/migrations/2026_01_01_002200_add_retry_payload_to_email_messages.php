<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Wiederholungspuffer fuer zeitweilig gescheiterte Transaktionsmails
|------------------------------------------------------------------------------
|
| Eine Transaktionsmail, die an einem zeitweiligen Fehler des Postausgangs
| scheitert (Verbindung, Anmeldung, Greylisting), wird bis zu dreimal innerhalb
| von 24 Stunden erneut versendet (MailDispatcher::erneutSenden, Zeitplan
| smartabrechnen:retry-failed-emails, Adminhandlung in der Kommunikation).
|
| Dafuer haelt email_messages.retry_payload die serialisierte Nachricht. Die
| Spalte ist ueber den Modellcast verschluesselt, weil eine Nachricht einen
| zeitlich begrenzten Downloadlink tragen kann. Sie wird nach erfolgreichem
| Versand, bei dauerhafter Unzustellbarkeit und nach Ablauf des
| Wiederholungsfensters geleert. Das Protokoll selbst (Vorlage, Empfaenger,
| Status, Fehler) bleibt unveraendert datensparsam.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('email_messages', 'retry_payload')) {
            return;
        }

        Schema::table('email_messages', function (Blueprint $table): void {
            $table->text('retry_payload')->nullable()->after('error_message')
                ->comment('Verschluesselte Nachricht fuer die Wiederholung, nur bei zeitweiligem Fehler');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('email_messages', 'retry_payload')) {
            return;
        }

        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropColumn('retry_payload');
        });
    }
};
