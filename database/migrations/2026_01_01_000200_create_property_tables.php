<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Vermieter, Objekte und Einheiten
|------------------------------------------------------------------------------
|
| Dokumentierte Genauigkeiten, niemals FLOAT oder DOUBLE:
|   Flaechen            DECIMAL(10,4)  Quadratmeter mit vier Nachkommastellen
|   Miteigentumsanteile DECIMAL(12,6)  Zaehler und Nenner, zum Beispiel 87/1000
|   Verbrauch           DECIMAL(14,4)  Einheit je Messeinrichtung
|   Prozentwerte        DECIMAL(7,4)   zum Beispiel 33,3333 Prozent
|
| Geldbetraege sind ausschliesslich BIGINT in Cent, Spaltenname endet auf _cent.
|
| Die Bankverbindung des Vermieters wird anwendungsseitig verschluesselt
| gespeichert (Eloquent-Cast "encrypted") und erscheint nur dann in einer
| Mieterabrechnung, wenn der Vermieter dies ausdruecklich waehlt.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlords', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // Absender und inhaltlich Verantwortlicher der Betriebskostenabrechnung
            // ist der Vermieter, nicht die Hausverwaltung Mueller GmbH.
            $table->string('sender_name');
            $table->string('company_name')->nullable();
            $table->string('address_line');
            $table->string('address_extra')->nullable();
            $table->string('postal_code', 16);
            $table->string('city', 120);
            $table->string('country', 2)->default('DE');
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();

            $table->text('iban')->nullable()
                ->comment('Anwendungsseitig verschluesselt, niemals im Klartext loggen');
            $table->text('bic')->nullable()
                ->comment('Anwendungsseitig verschluesselt');
            $table->string('account_holder')->nullable();
            $table->boolean('show_bank_details_on_statement')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'sender_name']);
        });

        Schema::create('properties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Mandantenspalte. Jede Query ist hierauf zu scopen, zusaetzlich greift
            // die PropertyPolicy mit Object-Level-Check.
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUlid('landlord_id')->nullable()
                ->constrained('landlords')->nullOnDelete();

            $table->string('label')->comment('Interne Bezeichnung des Objekts');
            $table->string('address_line');
            $table->string('address_extra')->nullable();
            $table->string('postal_code', 16);
            $table->string('city', 120);
            $table->string('country', 2)->default('DE');

            $table->string('kind', 48)->default('MEHRFAMILIENHAUS')
                ->comment('PHP-Enum App\Enums\PropertyKind');
            $table->string('weg_name')->nullable()
                ->comment('Bezeichnung der WEG, soweit aus Unterlagen bekannt');

            $table->decimal('total_living_area_sqm', 10, 4)->nullable();
            $table->decimal('total_heated_area_sqm', 10, 4)->nullable();
            $table->decimal('mea_denominator', 12, 6)->nullable()
                ->comment('Nenner der Miteigentumsanteile, zum Beispiel 1000,000000');

            // Bezeichnungen der individuellen Schluessel 1 bis 5 je Objekt. Die Werte
            // liegen je Einheit in units.individual_key_n_value.
            $table->string('individual_key_1_label', 120)->nullable();
            $table->string('individual_key_2_label', 120)->nullable();
            $table->string('individual_key_3_label', 120)->nullable();
            $table->string('individual_key_4_label', 120)->nullable();
            $table->string('individual_key_5_label', 120)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'postal_code']);
            $table->index('deleted_at');
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Denormalisierte Mandantenspalte als zweite Verteidigungslinie.
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();

            $table->string('label', 120)->comment('Einheitenbezeichnung, zum Beispiel WE 3');
            $table->string('location', 190)->nullable()->comment('Lage, zum Beispiel 2. OG links');
            $table->string('unit_number', 60)->nullable()->comment('Wohnungsnummer der WEG');

            $table->decimal('living_area_sqm', 10, 4)->nullable();
            $table->decimal('heated_area_sqm', 10, 4)->nullable();
            $table->decimal('mea', 12, 6)->nullable()
                ->comment('Zaehler der Miteigentumsanteile zum Nenner des Objekts');
            $table->unsignedSmallInteger('room_count')->nullable();

            $table->decimal('individual_key_1_value', 14, 4)->nullable();
            $table->decimal('individual_key_2_value', 14, 4)->nullable();
            $table->decimal('individual_key_3_value', 14, 4)->nullable();
            $table->decimal('individual_key_4_value', 14, 4)->nullable();
            $table->decimal('individual_key_5_value', 14, 4)->nullable();

            $table->boolean('is_commercial')->default(false);
            $table->boolean('is_owner_occupied')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'label']);
            $table->index(['organization_id', 'property_id']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('landlords');
    }
};
