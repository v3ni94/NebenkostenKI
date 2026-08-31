<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailSuppressionReason;
use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Gesperrte Empfaengeradresse nach Bounce, Beschwerde oder Abmeldung.
 *
 * @property string $id
 * @property string $email
 * @property EmailSuppressionReason $reason
 * @property Carbon $suppressed_at
 * @property string|null $source
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
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
            'reason' => EmailSuppressionReason::class,
            'suppressed_at' => 'datetime',
        ];
    }
}
