<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay-Schutz des TOTP-Zweitfaktors.
 *
 * RFC 6238, Abschnitt 5.2: Ein einmal akzeptierter Code darf nicht erneut
 * akzeptiert werden. Die Spalte haelt den Zaehlerstand des zuletzt
 * angenommenen Zeitfensters. Angenommen wird nur noch ein Code, dessen
 * Zaehler groesser als der gespeicherte Wert ist.
 *
 * Idempotent: Auf einer Bestandsdatenbank mit vorhandener Spalte ist der Lauf
 * folgenlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'two_factor_last_counter')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // INT genuegt: Der Zaehler ist die Unixzeit geteilt durch 30 und
            // bleibt bis weit ueber das Jahr 3000 unter 2^31. Kein Geldbetrag,
            // deshalb bewusst kein BIGINT mit Endung _cent.
            $table->integer('two_factor_last_counter')->nullable()
                ->after('two_factor_recovery_codes')
                ->comment('Zaehler des zuletzt akzeptierten TOTP-Zeitfensters, Replay-Schutz');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_last_counter')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_last_counter');
        });
    }
};
