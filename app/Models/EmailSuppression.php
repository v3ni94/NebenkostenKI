<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailSuppressionReason;
use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Gesperrte Empfaengeradresse nach Bounce, Beschwerde oder Abmeldung.
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
