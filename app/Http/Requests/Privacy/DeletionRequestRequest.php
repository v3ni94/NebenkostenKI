<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Antrag auf Löschung des Kontos.
 *
 * Die ausdrückliche Bestätigung ist verbindlich, damit ein Fehlklick keinen
 * Löschantrag auslöst. Die Autorisierung selbst liegt in der Policy des
 * Controllers, nicht hier.
 */
final class DeletionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'bestaetigung' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bestaetigung.accepted' => 'Bitte bestätigen Sie, dass Sie die Löschung Ihres Kontos beantragen möchten.',
        ];
    }
}
