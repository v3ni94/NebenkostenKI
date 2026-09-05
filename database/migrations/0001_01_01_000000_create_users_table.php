<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Identitaet der Kundennutzer
|------------------------------------------------------------------------------
|
| Zielsystem ist MariaDB 10.11 LTS beziehungsweise 11.x mit InnoDB und utf8mb4.
| Die Migrationen laufen zusaetzlich auf SQLite, damit die Testsuite ohne
| Datenbankserver arbeiten kann. Es werden daher keine treiberspezifischen
| Raw-Statements, keine MySQL-ENUM-Spalten, keine CHECK-Constraints mit
| Subqueries und keine generierten Spalten mit JSON-Extraktion verwendet.
|
| Primaerschluessel sind ULIDs (char 26). Fortlaufende, oeffentlich erratbare
| IDs werden nicht verwendet. Alle Zeitstempel werden in der Anwendungszeitzone
| gespeichert, die nach ADR-018 Europe/Berlin ist (config app.timezone).
| Fachliche Fristen und Tagesgrenzen bildet die Anwendungsschicht ueber
| App\Support\BusinessTimezone.
|
| Fachliche Zeitpunkte werden als DATETIME angelegt, nicht als TIMESTAMP. Damit
| entfaellt auf MariaDB jede Abhaengigkeit von explicit_defaults_for_timestamp,
| es entsteht kein implizites ON UPDATE CURRENT_TIMESTAMP auf der ersten
| Zeitstempelspalte einer Tabelle, und die Werte sind nicht auf das Jahr 2038
| begrenzt. Nur created_at, updated_at und deleted_at bleiben beim Laravel-
| Standard.
|
| Statuswerte sind kurze string-Spalten mit PHP-Enum-Cast im Modell. Das ist
| migrationssicher und erweiterbar, ohne die Tabelle umbauen zu muessen.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();

            // Passworthash. Produktiv Argon2id, im Test der konfigurierte Treiber.
            $table->string('password');
            $table->rememberToken();

            $table->string('status', 48)->default('UNBESTAETIGT')
                ->comment('PHP-Enum App\Enums\UserStatus');
            $table->string('locale', 10)->default('de');
            $table->string('timezone', 64)->default('Europe/Berlin');

            // Optionale TOTP-Zwei-Faktor-Authentifizierung, fuer Admins Pflicht.
            $table->text('two_factor_secret')->nullable()
                ->comment('Anwendungsseitig verschluesselt');
            $table->dateTime('two_factor_confirmed_at')->nullable();

            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('deleted_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
