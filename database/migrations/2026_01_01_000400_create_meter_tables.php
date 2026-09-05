<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Messeinrichtungen und Ablesungen
|------------------------------------------------------------------------------
|
| Verbrauchswerte werden als DECIMAL(14,4) gespeichert, niemals als FLOAT.
|
| Fehlt bei einem Nutzerwechsel die Zwischenablesung, wird der Verbrauch nicht
| still geschaetzt. Die Ablesung bleibt null, es entsteht eine Pruefaufgabe. Eine
| ausdruecklich bestaetigte Ersatzverteilung wird ueber is_estimated und
| confirmed_at gekennzeichnet und in der Abrechnung sichtbar ausgewiesen.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()
                ->comment('Leer bedeutet Allgemeinzaehler des Objekts')
                ->constrained()->nullOnDelete();

            $table->string('meter_type', 48)
                ->comment('PHP-Enum App\Enums\MeterType');
            $table->string('meter_number', 120);
            $table->string('measurement_unit', 20)->nullable()
                ->comment('Zum Beispiel m3, kWh, Einheiten');
            $table->string('location', 190)->nullable();
            $table->date('installed_on')->nullable();
            $table->date('removed_on')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'meter_number', 'meter_type']);
            $table->index(['organization_id', 'meter_type']);
            $table->index('unit_id');
        });

        Schema::create('meter_readings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('meter_device_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->nullable()->constrained()->nullOnDelete();

            $table->date('read_on');
            $table->decimal('value', 14, 4);
            $table->string('reading_kind', 48)
                ->comment('PHP-Enum App\Enums\MeterReadingKind');
            $table->string('source', 48)->default('MANUELL')
                ->comment('PHP-Enum App\Enums\ValueSource');

            $table->boolean('is_estimated')->default(false)
                ->comment('Nur nach ausdruecklicher Bestaetigung des Nutzers zulaessig');
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignUlid('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->decimal('confidence', 7, 4)->nullable()
                ->comment('Konfidenz der Extraktion, 0,0000 bis 1,0000');
            $table->ulid('document_id')->nullable()
                ->comment('Herkunftsdokument, ohne Fremdschluessel wegen Migrationsreihenfolge');
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(['meter_device_id', 'read_on', 'reading_kind']);
            $table->index(['organization_id', 'read_on']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('meter_devices');
    }
};
