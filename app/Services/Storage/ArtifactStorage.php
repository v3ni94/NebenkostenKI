<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\ArtifactRejectedException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dauerhafte Ablage AUSSCHLIESSLICH vom System erzeugter Ergebnisartefakte.
 *
 * VERBINDLICHE SPERREN (Abschnitt 3.4, 19, ADR-007):
 *
 * 1. Es gibt keine Methode, die einen beliebigen Pfad oder eine beliebige
 *    Datei entgegennimmt. Jeder Schreibvorgang verlangt einen ArtifactType.
 *    Fuer Originaluploads, Seitenbilder und OCR-Texte existiert bewusst kein
 *    Artefakttyp, deshalb koennen sie hier nicht abgelegt werden.
 * 2. Zusaetzlich prueft assertArtifactContents() die Magic Bytes gegen den
 *    Artefakttyp. Ein PDF-Artefakt muss mit %PDF- beginnen, ein Paket mit der
 *    ZIP-Signatur. Ein JPEG, HEIC, XLSX oder CSV wird abgewiesen, auch bei
 *    einem Programmierfehler an der Aufrufstelle.
 * 3. Die Disk "temporary_uploads" ist als Ziel gesperrt, ebenso jede Kopie
 *    aus dem Quarantaenebereich in die Artefaktablage.
 * 4. Es gibt keine oeffentliche URL. Die Auslieferung laeuft ueber eine
 *    autorisierte Streaming-Route oder einen kurzlebigen signierten Link.
 */
final class ArtifactStorage
{
    /**
     * Disks, auf denen niemals ein Artefakt abgelegt wird.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DISKS = [TemporaryUploadStorage::DISK, 'public'];

    /**
     * @var list<string>
     */
    private const ALLOWED_DISKS = ['sftp', 's3', 'local'];

    public function __construct(private readonly ?string $diskName = null) {}

    /**
     * Aktive Artefaktdisk. Produktiv "sftp", im Testlauf "local"
     * (FILESYSTEM_DISK=local in phpunit.xml). Ein echter SFTP-Server wird im
     * Test niemals verwendet.
     */
    public function diskName(): string
    {
        if ($this->diskName !== null) {
            return $this->assertAllowedDisk($this->diskName);
        }

        $configured = config('filesystems.default');

        return $this->assertAllowedDisk(is_string($configured) && $configured !== '' ? $configured : 'sftp');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Schreibt ein Artefakt. Der einzige Weg in die dauerhafte Ablage.
     *
     * @param  string  $organizationId  Mandantentrennung bereits im Pfad
     *
     * @throws ArtifactRejectedException
     */
    public function put(ArtifactType $type, string $organizationId, string $contents): ArtifactReference
    {
        $this->assertArtifactContents($type, $contents);

        $path = $this->buildPath($type, $organizationId);

        $this->disk()->put($path, $contents);

        return new ArtifactReference(
            $type,
            $this->diskName(),
            $path,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    /**
     * Ausdruecklich gesperrter Weg. Die Methode existiert, damit ein Aufruf
     * mit einer Quelldatei nicht versehentlich ueber einen generischen
     * Kopierbefehl an den Sperren vorbeilaeuft.
     *
     * @throws ArtifactRejectedException immer
     */
    public function copyFromDisk(string $sourceDisk, string $sourcePath): never
    {
        throw ArtifactRejectedException::sourceDisk($sourceDisk);
    }

    public function exists(ArtifactReference|string $artifact): bool
    {
        return $this->disk()->exists($this->pathOf($artifact));
    }

    public function size(ArtifactReference|string $artifact): int
    {
        $path = $this->pathOf($artifact);

        return $this->disk()->exists($path) ? (int) $this->disk()->size($path) : 0;
    }

    public function get(ArtifactReference|string $artifact): ?string
    {
        $path = $this->pathOf($artifact);

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function delete(ArtifactReference|string $artifact): bool
    {
        return $this->disk()->delete($this->pathOf($artifact));
    }

    /**
     * Ausgabe an den Browser. Der Dateiname ist ein neutraler, vom System
     * vergebener Name, niemals ein Originaldateiname.
     */
    public function download(ArtifactReference|string $artifact, string $downloadName, string $mimeType): StreamedResponse
    {
        return $this->disk()->download($this->pathOf($artifact), $downloadName, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Technische Sperre gegen Originaluploads.
     *
     * @throws ArtifactRejectedException
     */
    public function assertArtifactContents(ArtifactType $type, string $contents): void
    {
        if ($contents === '' || ! str_starts_with($contents, $type->magicPrefix())) {
            throw ArtifactRejectedException::contents($type->extension());
        }
    }

    private function buildPath(ArtifactType $type, string $organizationId): string
    {
        $tenant = preg_replace('/[^A-Za-z0-9]/', '', $organizationId);
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : 'ohne-mandant';

        return sprintf(
            '%s/%s/%s.%s',
            $type->directory(),
            $tenant,
            Str::lower((string) Str::ulid()),
            $type->extension(),
        );
    }

    private function pathOf(ArtifactReference|string $artifact): string
    {
        return $artifact instanceof ArtifactReference ? $artifact->path : $artifact;
    }

    /**
     * @throws ArtifactRejectedException
     */
    private function assertAllowedDisk(string $disk): string
    {
        if (in_array($disk, self::FORBIDDEN_DISKS, true) || ! in_array($disk, self::ALLOWED_DISKS, true)) {
            throw ArtifactRejectedException::disk($disk);
        }

        return $disk;
    }
}
