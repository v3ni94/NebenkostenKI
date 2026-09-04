<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anfordernder Nutzer eines Datenexports.
 *
 * Ein DSGVO-Export enthaelt Kontodaten des Antragstellers und Daten aller
 * seiner Mandanten. In einem geteilten Mandanten darf ihn deshalb nur der
 * Antragsteller selbst sehen und herunterladen, nicht jedes Mitglied. Die
 * Spalte bindet das Artefakt an den Nutzer; die Datenschutzseite filtert
 * zusaetzlich zum Mandanten darauf.
 *
 * Bewusst ohne Fremdschluessel: Bei der Kontoloeschung werden die Exporte
 * des Nutzers ausdruecklich vor dem Nutzer selbst entfernt, ein Nullsetzen
 * ueber die Datenbank ist nicht gewuenscht.
 *
 * Idempotent: Auf einer Bestandsdatenbank mit vorhandener Spalte ist der Lauf
 * folgenlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('generated_documents', 'requested_by_user_id')) {
            return;
        }

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->ulid('requested_by_user_id')->nullable()
                ->after('replaced_by_document_id')
                ->comment('Anfordernder Nutzer, nur bei DSGVO-Exporten gesetzt');
            $table->index('requested_by_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('generated_documents', 'requested_by_user_id')) {
            return;
        }

        Schema::table('generated_documents', function (Blueprint $table): void {
            $table->dropIndex(['requested_by_user_id']);
            $table->dropColumn('requested_by_user_id');
        });
    }
};
