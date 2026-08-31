<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\GeneratedDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auslieferung erzeugter Ergebnisartefakte.
 *
 * VERBINDLICH (Abschnitt 3.4 und 19):
 * - Ausgeliefert werden ausschliesslich vom System erzeugte Artefakte:
 *   Vorschau-PDFs, Final-PDFs, ZIP-Pakete, HVM-Rechnungen und DSGVO-Exporte.
 *   Es gibt keinen Weg, ueber diesen Controller eine Originaldatei abzurufen;
 *   Originale sind zu diesem Zeitpunkt bereits geloescht.
 * - Es gibt keinen oeffentlichen Pfad. Der Zugriff laeuft ueber diese
 *   autorisierte Streaming-Route oder ueber einen kurzlebigen signierten Link.
 * - Ein signierter Link ersetzt die Autorisierung NICHT. Auch dort wird die
 *   Zugehoerigkeit zum Mandanten geprueft, damit ein weitergegebener Link nicht
 *   wie ein dauerhaftes Zugriffsrecht wirkt.
 *
 * Ein fremdes Artefakt fuehrt zu 404 und nicht zu 403: Ein 403 wuerde
 * bestaetigen, dass die ID existiert.
 */
class DownloadController extends Controller
{
    public function __construct(private readonly ArtifactStorage $artifacts) {}

    /**
     * Autorisierte Streaming-Route fuer angemeldete Nutzer.
     */
    public function stream(Request $request, GeneratedDocument $generatedDocument): StreamedResponse
    {
        $this->assertAccessible($request, $generatedDocument);

        return $this->deliver($generatedDocument);
    }

    /**
     * Kurzlebiger signierter Link. Die Gueltigkeit prueft die Middleware
     * "signed"; die Zugehoerigkeit zum Mandanten prueft zusaetzlich diese
     * Methode.
     */
    public function signed(Request $request, GeneratedDocument $generatedDocument): StreamedResponse
    {
        $this->assertAccessible($request, $generatedDocument);

        return $this->deliver($generatedDocument);
    }

    private function assertAccessible(Request $request, GeneratedDocument $generatedDocument): void
    {
        $status = $generatedDocument->getAttribute('status');

        abort_unless($status === GeneratedDocumentStatus::AKTIV, 404);

        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $organizationId = $generatedDocument->getAttribute('organization_id');

        abort_unless(
            is_string($organizationId) && in_array($organizationId, $user->organizationIds(), true),
            404
        );

        // Ein Artefakt liegt niemals im Kurzzeitbereich. Weicht die Disk ab,
        // wird nicht ausgeliefert.
        abort_unless(
            $generatedDocument->getAttribute('storage_disk') === $this->artifacts->diskName(),
            404
        );
    }

    private function deliver(GeneratedDocument $generatedDocument): StreamedResponse
    {
        $path = $generatedDocument->getAttribute('storage_path');

        abort_unless(is_string($path) && $path !== '' && $this->artifacts->exists($path), 404);

        return $this->artifacts->download(
            $path,
            $this->downloadName($generatedDocument),
            $this->mimeType($path),
        );
    }

    /**
     * Neutraler, vom System vergebener Dateiname. Er enthaelt keinen
     * Originaldateinamen und keine personenbezogenen Angaben.
     */
    private function downloadName(GeneratedDocument $generatedDocument): string
    {
        $kind = $generatedDocument->getAttribute('kind');
        $variant = $generatedDocument->getAttribute('variant');

        $base = match (true) {
            is_object($kind) && property_exists($kind, 'value') => (string) $kind->value,
            default => 'DOKUMENT',
        };

        $suffix = is_object($variant) && property_exists($variant, 'value') ? '-'.$variant->value : '';

        return strtolower($base.$suffix).'.'.$this->extension($generatedDocument);
    }

    private function extension(GeneratedDocument $generatedDocument): string
    {
        $path = (string) $generatedDocument->getAttribute('storage_path');

        return str_ends_with($path, '.zip') ? 'zip' : 'pdf';
    }

    private function mimeType(string $path): string
    {
        return str_ends_with($path, '.zip') ? 'application/zip' : 'application/pdf';
    }
}
