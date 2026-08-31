<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\Document;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Erzeugt ausschliesslich Metadaten. Es gibt keinen Dateinamen und keinen
 * Storage-Key, weil Originaldateien nicht dauerhaft gespeichert werden.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 500);

        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'sequence_number' => $sequence,
            'source_label' => sprintf('Dokument %02d - Grundsteuerbescheid', $sequence),
            'document_type' => DocumentType::GRUNDSTEUERBESCHEID,
            'document_type_confidence' => '0.9400',
            'type_assigned_manually' => false,
            'mime_type' => 'application/pdf',
            'original_byte_size' => 348212,
            'page_count' => 2,
            'fingerprint_hmac' => hash_hmac('sha256', 'testdokument-'.$sequence, 'testschluessel'),
            'processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN,
            'security_checked_at' => now(),
            'malware_scanner_driver' => 'disabled',
            'malware_scan_clean' => true,
            'classified_at' => now(),
            'extracted_at' => now(),
            'original_deleted_at' => now(),
            'deletion_status' => DeletionStatus::ERFOLGREICH,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
            'extracted_at' => null,
            'original_deleted_at' => null,
            'deletion_status' => DeletionStatus::OFFEN,
        ]);
    }

    public function deletionFailed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_deleted_at' => null,
            'deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ]);
    }
}
