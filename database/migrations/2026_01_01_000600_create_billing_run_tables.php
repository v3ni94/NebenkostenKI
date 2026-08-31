<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Abrechnungslaeufe, Eingabeversionen und Calculation Snapshots
|------------------------------------------------------------------------------
|
| Geldbetraege sind BIGINT in Cent. Prozentwerte sind DECIMAL(7,4).
|
| billing_runs.active_calculation_snapshot_id ist bewusst eine ULID-Spalte ohne
| Fremdschluessel. Ein echter Fremdschluessel wuerde eine zirkulaere Abhaengigkeit
| zwischen billing_runs und calculation_snapshots erzeugen, und ein nachtraegliches
| ALTER TABLE ADD CONSTRAINT ist auf SQLite nicht moeglich. Die referenzielle
| Integritaet dieser einen Spalte sichert die Anwendungsschicht in derselben
| Transaktion, in der der Snapshot entsteht.
|
| Ein bezahlter Snapshot wird gesperrt (status GESPERRT, locked_at gesetzt) und
| nie ueberschrieben. Korrekturen erzeugen einen neuen Snapshot, der alte behaelt
| den Status ERSETZT und bleibt reproduzierbar.
|
| JSON-Spalten werden ausschliesslich als Nutzlast gelesen und geschrieben. Es
| werden keine Funktionsindizes, keine generierten Spalten mit JSON-Extraktion
| und keine CHECK-Constraints mit Subqueries verwendet, damit MariaDB 10.11 und
| SQLite dieselbe Struktur tragen.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('landlord_id')->nullable()
                ->constrained('landlords')->nullOnDelete();

            // Vorjahresreferenz fuer die Folgejahresuebernahme. Vorjahreswerte dienen
            // ausschliesslich dem Vergleich und niemals als neue Kosten.
            $table->foreignUlid('previous_billing_run_id')->nullable()
                ->constrained('billing_runs')->nullOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('billing_year')
                ->comment('Jahr des Abrechnungszeitraums, Grundlage der Erinnerungen');

            $table->string('mode', 48)->default('QUICK_CONDO')
                ->comment('PHP-Enum App\Enums\BillingMode');
            $table->string('status', 48)->default('DRAFT')
                ->comment('PHP-Enum App\Enums\BillingRunStatus');
            $table->unsignedTinyInteger('wizard_step')->default(1)
                ->comment('Aktueller Schritt des gefuehrten Ablaufs, 1 bis 12');
            $table->string('heating_supply_case', 48)->nullable()
                ->comment('PHP-Enum App\Enums\HeatingSupplyCase');

            $table->ulid('active_calculation_snapshot_id')->nullable()
                ->comment('Siehe Kommentar oben, kein Fremdschluessel');

            // Preisstatus. Der Endpreis wird vor dem Checkout serverseitig anhand der
            // tatsaechlich erzeugten Mieterabrechnungen neu berechnet.
            $table->unsignedInteger('statement_count')->default(0);
            $table->bigInteger('price_per_statement_gross_cent')->nullable();
            $table->bigInteger('price_base_gross_cent')->nullable();
            $table->bigInteger('price_total_gross_cent')->nullable();
            $table->decimal('vat_rate_percent', 7, 4)->nullable()
                ->comment('Zum Zeitpunkt der Preisfestsetzung gueltiger Steuersatz');
            $table->timestamp('price_quoted_at')->nullable();
            $table->timestamp('price_locked_at')->nullable();

            $table->unsignedBigInteger('uploaded_bytes')->default(0)
                ->comment('Summe der ursprünglichen Dateigroessen fuer das Laufzeitlimit');

            $table->timestamp('review_confirmed_at')->nullable()
                ->comment('Nutzer hat alle Werte und Ergebnisse geprueft');
            $table->timestamp('responsibility_confirmed_at')->nullable()
                ->comment('Nutzer hat die Verantwortung als Vermieter uebernommen');

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->string('failure_message', 500)->nullable()
                ->comment('Verstaendliche Handlungsempfehlung, keine Rohdaten');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'billing_year']);
            $table->index(['property_id', 'period_start']);
            $table->index('status');
            $table->index('active_calculation_snapshot_id');
            $table->index('deleted_at');
        });

        Schema::create('billing_run_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->json('payload')
                ->comment('Unveraenderliche Fassung der abrechnungsrelevanten Nutzereingaben');
            $table->string('payload_hash', 64)->comment('SHA-256 der Nutzlast');
            $table->string('reason', 500)->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['billing_run_id', 'version_number']);
            $table->index('organization_id');
        });

        Schema::create('calculation_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('replaced_by_snapshot_id')->nullable()
                ->constrained('calculation_snapshots')->nullOnDelete();

            $table->unsignedInteger('version_number');
            $table->json('input')
                ->comment('Vollstaendige normalisierte Eingabe der Domainschicht');
            $table->json('result')
                ->comment('Vollstaendiges Ergebnis samt nachvollziehbarem Rechenweg');

            $table->string('domain_version', 32);
            $table->string('ruleset_version', 32);
            $table->string('hash', 64)->comment('SHA-256 ueber Eingabe, Ergebnis und Versionen');
            $table->string('status', 48)->default('BERECHNET')
                ->comment('PHP-Enum App\Enums\CalculationSnapshotStatus');

            $table->unsignedInteger('statement_count')->default(0);
            $table->bigInteger('total_apportionable_cent')->default(0);
            $table->bigInteger('total_prepayment_actual_cent')->default(0);
            $table->bigInteger('total_balance_cent')->default(0);

            $table->timestamp('locked_at')->nullable()
                ->comment('Gesetzt bei bestaetigter Zahlung, danach unveraenderlich');
            $table->foreignUlid('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['billing_run_id', 'version_number']);
            $table->index(['organization_id', 'status']);
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_snapshots');
        Schema::dropIfExists('billing_run_versions');
        Schema::dropIfExists('billing_runs');
    }
};
