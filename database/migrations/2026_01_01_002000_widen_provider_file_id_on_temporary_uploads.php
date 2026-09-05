<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Provider-Datei-ID wird waehrend der Verarbeitung verschluesselt im
 * Kurzzeitdatensatz gefuehrt (Cast "encrypted" in App\Models\TemporaryUpload).
 * Das verschluesselte Feld ist laenger als die bisherige Spalte mit 190
 * Zeichen, deshalb wird die Spalte auf TEXT erweitert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('temporary_uploads', 'provider_file_id')) {
            return;
        }

        Schema::table('temporary_uploads', static function (Blueprint $table): void {
            $table->text('provider_file_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('temporary_uploads', 'provider_file_id')) {
            return;
        }

        Schema::table('temporary_uploads', static function (Blueprint $table): void {
            $table->string('provider_file_id', 190)->nullable()->change();
        });
    }
};
