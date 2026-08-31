<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Kostenkategorien
|------------------------------------------------------------------------------
|
| Jede Kategorie ist ueber valid_from und valid_to versioniert. Gesetzes- oder
| Bewertungsaenderungen erzeugen einen neuen Datensatz mit demselben code und
| einem neuen Gueltigkeitsbeginn. Alte Abrechnungen bleiben dadurch exakt
| reproduzierbar, weil jede Kostenposition auf die zum Abrechnungszeitraum
| gueltige Kategorie-ULID verweist.
|
| Der Unique-Schluessel liegt auf (code, valid_from). Individuelle Kategorien
| eines Kunden erhalten daher einen global eindeutigen code, der von der
| Anwendungsschicht erzeugt wird, und tragen zusaetzlich die organization_id.
|
| Die Umlagebewertung ist ein fachlicher Vorschlag und keine Rechtsfreigabe.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Leer bedeutet: systemweite Standardkategorie.
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('code', 80);
            $table->string('name', 190);
            $table->string('betrkv_reference', 190)->nullable()
                ->comment('Textfeld, zum Beispiel BetrKV Paragraf 2 Nummer 1');

            $table->string('apportionment_status', 48)
                ->comment('PHP-Enum App\Enums\ApportionmentStatus');
            $table->string('default_allocation_key_type', 48)
                ->comment('PHP-Enum App\Enums\AllocationKeyType');
            $table->string('paragraph_35a_type', 48)->default('NONE')
                ->comment('PHP-Enum App\Enums\Paragraph35aType');

            $table->boolean('excluded_from_apportionment_by_default')->default(false)
                ->comment('Standardausschluss aus der Mieterumlage nach Abschnitt 12.2');
            $table->boolean('requires_contract_basis')->default(false)
                ->comment('Umlage nur bei konkreter mietvertraglicher Vereinbarung');
            $table->boolean('requires_manual_review')->default(false)
                ->comment('Erzeugt immer eine Pruefaufgabe');
            $table->boolean('is_heating_related')->default(false);
            $table->boolean('is_warm_water_related')->default(false);
            $table->boolean('supports_labor_share')->default(false)
                ->comment('Lohnanteil nach Paragraf 35a EStG moeglich, nur bei Nachweis');
            $table->boolean('is_custom')->default(false);

            $table->text('warning_note')->nullable()
                ->comment('Deutscher Warnhinweis fuer die Oberflaeche');
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->date('valid_from');
            $table->date('valid_to')->nullable()->comment('Leer bedeutet aktuell gueltig');

            $table->timestamps();

            $table->unique(['code', 'valid_from']);
            $table->index(['apportionment_status', 'sort_order']);
            $table->index(['valid_from', 'valid_to']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_categories');
    }
};
