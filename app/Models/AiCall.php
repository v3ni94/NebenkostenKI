<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\AiProvider;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nachweismetadaten eines KI-Aufrufs.
 *
 * DATENSCHUTZ: Es werden keine rohen Prompts, keine rohen Antworten, keine
 * Base64-Dateiinhalte und keine Provider-Datei-IDs gespeichert. Nach der
 * JSON-Schema-Validierung wird die rohe Modellantwort verworfen.
 */
class AiCall extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<AiCallFactory> */
    use HasFactory;

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
            'provider' => AiProvider::class,
            'purpose' => AiCallPurpose::class,
            'status' => AiCallStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'file_count' => 'integer',
            'cost_cent' => 'integer',
            'duration_ms' => 'integer',
            'schema_valid' => 'boolean',
            'attempt' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<AiPromptVersion, $this>
     */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'ai_prompt_version_id');
    }
}
