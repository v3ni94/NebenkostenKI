<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use Database\Factories\SourceDeletionEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Datensparsamer Loeschnachweis fuer Originaldateien und Providerdateien.
 *
 * DATENSCHUTZ: Der Nachweis enthaelt keinen Dateiinhalt und keinen Dateinamen.
 * document_id und organization_id sind bewusst ohne Fremdschluessel ausgefuehrt,
 * damit der Nachweis auch nach Loeschung des Dokuments erhalten bleibt.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $document_id
 * @property string|null $temporary_upload_id
 * @property DeletionStatus $local_deletion_status
 * @property DeletionStatus $provider_deletion_status
 * @property AiProvider|null $provider
 * @property Carbon $occurred_at
 * @property int $attempt
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SourceDeletionEvent extends Model
{
    /** @use HasFactory<SourceDeletionEventFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_deletion_status' => DeletionStatus::class,
            'provider_deletion_status' => DeletionStatus::class,
            'provider' => AiProvider::class,
            'occurred_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    /**
     * Ereignisse, die im Adminbereich als Datenschutzalarm erscheinen.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $alerting = [
            DeletionStatus::FEHLGESCHLAGEN->value,
            DeletionStatus::UEBERFAELLIG->value,
            DeletionStatus::OFFEN->value,
        ];

        $query->where(function (Builder $inner) use ($alerting): void {
            $inner->whereIn('local_deletion_status', $alerting)
                ->orWhereIn('provider_deletion_status', $alerting);
        });
    }
}
