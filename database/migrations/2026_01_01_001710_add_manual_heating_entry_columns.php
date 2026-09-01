<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manuelle Heizkostenerfassung fuer Fall B (Zentralheizung ohne externen
 * Abrechner).
 *
 * BEGRUENDUNG: Die vorhandenen Spalten reichen nicht aus. Es fehlen
 *
 *  - die Kennzeichnung der manuellen Erfassung und die Entscheidung des
 *    Anwenders, welche Quelle bei mehreren Quellen gilt,
 *  - die Herkunft der Berechnung als Freitext fuer das interne Blatt,
 *  - die getrennten CO2-Kostenanteile von Vermieter und Mieter sowie die
 *    sonstigen Kosten des Heizbetriebs, jeweils auf Statement- und
 *    Zeilenebene,
 *  - die Nutzungstage je Zeile, damit die zeitanteilige Verteilung bei
 *    Mieterwechsel nachvollziehbar bleibt,
 *  - die Kennzeichnung der aus der manuellen Erfassung gebildeten
 *    Kostenpositionen, damit ein erneutes Speichern sie ersetzt statt sie zu
 *    verdoppeln.
 *
 * Alle Spalten sind treiberneutral (MariaDB und SQLite) und nullable
 * beziehungsweise mit Standardwert, damit vorhandene Datensaetze unveraendert
 * gueltig bleiben. Geldbetraege sind Integer in Cent (Grundsatz 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heating_statements', function (Blueprint $table): void {
            $table->boolean('manual_entry')->default(false)
                ->comment('Vom Anwender selbst gerechnete Betraege, Fall B ohne externen Abrechner');
            $table->text('calculation_origin')->nullable()
                ->comment('Herkunft der Berechnung, erscheint nur im internen Blatt');
            $table->bigInteger('co2_landlord_cent')->nullable()
                ->comment('CO2-Kostenanteil des Vermieters, wird nicht umgelegt');
            $table->bigInteger('co2_tenant_cent')->nullable()
                ->comment('CO2-Kostenanteil des Mieters');
            $table->bigInteger('other_cost_cent')->nullable()
                ->comment('sonstige Kosten des Heizbetriebs');
            $table->string('manual_source_decision', 24)->nullable()
                ->comment('MANUELL oder EXTERN, Entscheidung des Anwenders bei mehreren Quellen');
        });

        Schema::table('heating_statement_lines', function (Blueprint $table): void {
            $table->bigInteger('share_co2_landlord_cent')->nullable();
            $table->bigInteger('share_co2_tenant_cent')->nullable();
            $table->bigInteger('share_other_cent')->nullable();
            $table->unsignedInteger('usage_days')->nullable()
                ->comment('Nutzungstage des Zeitraums, Grundlage der zeitanteiligen Verteilung');
            $table->string('usage_period_label', 60)->nullable();
        });

        Schema::table('cost_items', function (Blueprint $table): void {
            $table->boolean('manual_heating_entry')->default(false)
                ->comment('Position stammt aus der manuellen Heizkostenerfassung, Fall B');
        });
    }

    public function down(): void
    {
        Schema::table('cost_items', function (Blueprint $table): void {
            $table->dropColumn('manual_heating_entry');
        });

        Schema::table('heating_statement_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'share_co2_landlord_cent',
                'share_co2_tenant_cent',
                'share_other_cent',
                'usage_days',
                'usage_period_label',
            ]);
        });

        Schema::table('heating_statements', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_entry',
                'calculation_origin',
                'co2_landlord_cent',
                'co2_tenant_cent',
                'other_cost_cent',
                'manual_source_decision',
            ]);
        });
    }
};
