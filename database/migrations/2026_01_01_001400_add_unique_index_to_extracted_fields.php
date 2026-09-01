<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| Idempotenz der Extraktion auf Datenbankebene
|==============================================================================
|
| Ein Dokument darf je Schema-Key genau einen extrahierten Wert besitzen. Die
| Anwendungsschicht setzt das bereits durch: ExtractedFieldPersister ersetzt
| vorhandene Felder kontrolliert, damit ein Wiederholungslauf keine Dubletten
| erzeugt. Der Unique-Index sichert dieselbe Zusage auf der untersten Ebene ab,
| falls ein spaeterer Codepfad daran vorbeischreibt.
|
| Der Schema-Key ist ein vollstaendig qualifizierter Pfad und enthaelt bei
| Listen den Index, zum Beispiel "kostenarten[3].betrag_cent". Mehrere
| Positionen desselben Dokuments kollidieren daher nicht.
|
| Laenge: schema_key ist string(190). Zusammen mit der ULID-Spalte bleibt der
| Index unter der Schluessellaengengrenze von InnoDB mit utf8mb4.
|
| Der bisherige einfache Index auf denselben Spalten wird ersetzt statt
| ergaenzt. Ein Unique-Index bedient dieselben Abfragen; zwei Indizes auf
| identischer Spaltenfolge waeren nur zusaetzlicher Schreibaufwand.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_fields', function (Blueprint $table): void {
            $table->dropIndex('extracted_fields_document_id_schema_key_index');
            $table->unique(['document_id', 'schema_key'], 'extracted_fields_document_schema_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_fields', function (Blueprint $table): void {
            $table->dropUnique('extracted_fields_document_schema_key_unique');
            $table->index(['document_id', 'schema_key'], 'extracted_fields_document_id_schema_key_index');
        });
    }
};
