<?php

declare(strict_types=1);

namespace App\Http\Requests\Upload;

use App\Http\Requests\GermanFormRequest;
use App\Models\BillingRun;
use App\Models\TemporaryUpload;
use Illuminate\Support\Facades\Gate;

/**
 * Abschluss eines Chunk-Uploads.
 *
 * Der Browser meldet nur, dass alle Abschnitte uebertragen wurden. Die
 * Vollstaendigkeit wird serverseitig anhand der tatsaechlich vorhandenen
 * Abschnitte geprueft, niemals anhand der Angabe des Browsers.
 *
 * Die angekuendigte Dateiendung wird erneut mitgegeben, weil sie fuer den
 * Abgleich mit den Magic Bytes benoetigt wird. Sie ist ein technischer
 * Parameter, kein Dateiname.
 */
class CompleteUploadRequest extends GermanFormRequest
{
    /**
     * Autorisierung vor Validierung, objektbezogen ueber den Abrechnungslauf.
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
            'erweiterung' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'erweiterung.required' => 'Das Dateiformat konnte nicht ermittelt werden. Bitte laden Sie die Datei erneut hoch.',
            'erweiterung.regex' => 'Dieses Dateiformat wird nicht unterstützt.',
        ];
    }

    public function fileExtension(): string
    {
        return strtolower((string) $this->input('erweiterung'));
    }
}
