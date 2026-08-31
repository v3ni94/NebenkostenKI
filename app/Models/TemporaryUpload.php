<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TemporaryUploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Einziger Ort mit einem Storage-Key auf eine Originaldatei.
 *
 * DATENSCHUTZ: Der Bereich liegt verschluesselt ausserhalb des Webroots, ohne
 * Backup und mit kurzer TTL. Nach erfolgreicher Loeschung wird der Datensatz
 * entfernt oder auf einen inhaltslosen Tombstone reduziert (storage_key null,
 * is_tombstone true). Ein unabhaengiger Cleanup-Job loescht ueberfaellige Dateien
 * auch dann, wenn die Verarbeitung haengen geblieben ist.
 */
class TemporaryUpload extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<TemporaryUploadFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['storage_key', 'provider_file_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'received_bytes' => 'integer',
            'first_chunk_at' => 'datetime',
            'expires_at' => 'datetime',
            'deletion_attempts' => 'integer',
            'deleted_at' => 'datetime',
            'is_tombstone' => 'boolean',
            'provider' => AiProvider::class,
            'provider_file_deleted_at' => 'datetime',
            'provider_deletion_status' => DeletionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Ueberfaellige Dateien fuer den unabhaengigen Cleanup-Job.
     *
     * @param  Builder<static>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('is_tombstone', false)
            ->where('expires_at', '<=', now());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePendingDeletion(Builder $query): void
    {
        $query->where('is_tombstone', false)->whereNotNull('storage_key');
    }
}
