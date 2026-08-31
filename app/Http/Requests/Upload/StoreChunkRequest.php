<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Models\BillingRun;
use App\Models\TemporaryUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Annahme eines einzelnen Dateiabschnitts.
 *
 * Der Abschnitt wird nicht als vollstaendige Datei validiert, weil ein
 * Abschnitt fuer sich keine gueltige Datei ist. Die vollstaendige Pruefkette
 * laeuft nach der Wiederzusammensetzung.
 */
class StoreChunkRequest extends FormRequest
{
    /**
     * Autorisierung vor Validierung: Ein fremder Mandant darf nicht einmal
     * erfahren, welche Felder erwartet werden.
     */
    public function authorize(): bool
    {
        $upload = $this->route('upload');

        if (! $upload instanceof TemporaryUpload) {
            return false;
        }

        $billingRun = $upload->document?->billingRun;

        return $billingRun instanceof BillingRun && Gate::allows('update', $billingRun);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'index' => ['required', 'integer', 'min:0', 'max:65535'],
            'abschnitt' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'index.required' => 'Der Dateiabschnitt ist unvollständig. Bitte laden Sie die Datei erneut hoch.',
            'abschnitt.required' => 'Es wurde kein Dateiabschnitt übertragen. Bitte laden Sie die Datei erneut hoch.',
            'abschnitt.file' => 'Der übertragene Dateiabschnitt ist ungültig. Bitte laden Sie die Datei erneut hoch.',
        ];
    }

    public function chunkIndex(): int
    {
        return (int) $this->input('index');
    }

    public function chunkFile(): UploadedFile
    {
        $file = $this->file('abschnitt');

        if (! $file instanceof UploadedFile) {
            abort(422, 'Es wurde kein Dateiabschnitt übertragen.');
        }

        return $file;
    }
}
