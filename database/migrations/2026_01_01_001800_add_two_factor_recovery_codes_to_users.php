<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiederherstellungscodes des Zweitfaktors.
 *
 * WARUM EINE EIGENE MIGRATION
 *
 * Die Spalten users.two_factor_secret und users.two_factor_confirmed_at gibt es
 * bereits aus 0001_01_01_000000_create_users_table. Es fehlten allein die
 * Wiederherstellungscodes. Bestehende Migrationen werden nicht rueckwirkend
 * geaendert, weil sie in Bestandsdatenbanken schon ausgefuehrt sind.
 *
 * INHALT DER SPALTE
 *
 * Eine JSON-Liste der EINZELN GEHASHTEN Codes, niemals Klartext. Ein
 * verbrauchter Code wird aus der Liste entfernt und ist damit entwertet. Der
 * Typ ist bewusst text und nicht json, damit die Migration ohne Sonderfall auf
 * MariaDB und auf SQLite laeuft; die Serialisierung uebernimmt der Cast am
 * Modell App\Models\User.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'two_factor_recovery_codes')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_recovery_codes')->nullable()
                ->after('two_factor_confirmed_at')
                ->comment('JSON-Liste einzeln gehashter Einmalcodes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_recovery_codes');
        });
    }
};
