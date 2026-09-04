<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Services\Storage\UploadLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Start eines Chunk-Uploads.
 *
 * DATENSCHUTZ: Der Dateiname wird ausschliesslich zur Bestimmung der
 * Dateiendung entgegengenommen und danach verworfen. Er wird weder gespeichert
 * noch in einen Queue-Payload noch in ein Protokoll uebernommen
 * (Abschnitt 6.3 Schritt 3). Die Anwendungsschicht erhaelt nur die Endung.
 *
 * Die Kategorie ist optional. Der Nutzer muss nichts einordnen, das System
 * klassifiziert selbst (Abschnitt 9, Schritt 2).
 */
class StartUploadRequest extends FormRequest
{
    /**
     * Objektbezogene Autorisierung ueber die Policy des Abrechnungslaufs, und
     * zwar VOR der Validierung. Ein fremder Mandant erhaelt damit 403 und keine
     * Rueckmeldung zu den Feldern. Der Controller prueft zusaetzlich erneut.
     */
    public function authorize(): bool
    {
        $billingRun = $this->route('billingRun');

        return $billingRun instanceof BillingRun && Gate::allows('update', $billingRun);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = UploadLimits::fromConfig();

        return [
            'dateiname' => ['required', 'string', 'max:255', $this->rejectsUnsupportedSpreadsheets()],
            'groesse' => ['required', 'integer', 'min:1', 'max:'.$limits->maxFileBytes],
            'kategorie' => ['nullable', Rule::enum(DocumentType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limits = UploadLimits::fromConfig();

        return [
            'dateiname.required' => 'Bitte wählen Sie eine Datei aus.',
            'groesse.required' => 'Die Dateigröße konnte nicht ermittelt werden. Bitte laden Sie die Datei erneut hoch.',
            'groesse.max' => sprintf(
                'Die Datei ist größer als %d MB. Bitte teilen Sie die Unterlage oder verringern Sie die Auflösung des Scans.',
                $limits->maxFileMegabytes()
            ),
            'kategorie.enum' => 'Die gewählte Kategorie ist nicht zulässig.',
        ];
    }

    /**
     * Nur die Endung. Der Name selbst verlaesst diese Klasse nicht.
     */
    public function fileExtension(): string
    {
        return self::extensionOf((string) $this->input('dateiname'));
    }

    /**
     * XLSX wird fuer den Start nicht ausgewertet: Die Provider verarbeiten
     * PDF, Bilder und Text, eine serverseitige Umwandlung der Tabelle gibt es
     * noch nicht. Die Datei wird deshalb bereits hier mit einer klaren
     * Handlungsanweisung abgelehnt, statt die Pruefkette zu durchlaufen und
     * erst in der Auswertung zu scheitern.
     *
     * @var list<string>
     */
    public const UNSUPPORTED_SPREADSHEET_EXTENSIONS = ['xlsx'];

    /**
     * Die Meldung nennt bewusst nicht den Dateinamen.
     */
    public const UNSUPPORTED_SPREADSHEET_MESSAGE = 'Excel-Tabellen (XLSX) werden derzeit nicht ausgewertet. Bitte speichern Sie die Tabelle als CSV oder PDF und laden Sie sie erneut hoch.';

    private function rejectsUnsupportedSpreadsheets(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (in_array(self::extensionOf($value), self::UNSUPPORTED_SPREADSHEET_EXTENSIONS, true)) {
                $fail(self::UNSUPPORTED_SPREADSHEET_MESSAGE);
            }
        };
    }

    private static function extensionOf(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false ? '' : strtolower(substr($name, $position + 1));
    }

    public function declaredByteSize(): int
    {
        return (int) $this->input('groesse');
    }

    public function suggestedType(): ?DocumentType
    {
        $value = $this->input('kategorie');

        return is_string($value) ? DocumentType::tryFrom($value) : null;
    }
}
