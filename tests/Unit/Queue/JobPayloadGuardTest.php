<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Services\Queue\JobPayloadGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prueft die technische Sperre fuer Queue-Payloads.
 *
 * VERBINDLICH (Abschnitt 19): In processing_jobs.payload gehoeren nur
 * Referenz-IDs und kurze technische Parameter, niemals Dateiinhalte, OCR-Texte,
 * Prompts oder Originaldateinamen.
 */
class JobPayloadGuardTest extends TestCase
{
    public function test_erlaubt_referenz_ids_und_technische_parameter(): void
    {
        $guard = new JobPayloadGuard;

        $payload = $guard->sanitize([
            'dokument_id' => '01JABCDEF0123456789ABCDEFG',
            'erweiterung' => 'pdf',
            'versuch' => 2,
            'wiederholung' => true,
            'seite' => null,
        ]);

        $this->assertSame('pdf', $payload['erweiterung']);
        $this->assertSame(2, $payload['versuch']);
    }

    /**
     * @return list<array{string}>
     */
    public static function verboteneSchluessel(): array
    {
        return [
            ['ocr_text'],
            ['dateiname'],
            ['inhalt'],
            ['prompt'],
            ['antwort'],
            ['storage_path'],
            ['base64'],
            ['email'],
            ['iban'],
            ['auszug'],
        ];
    }

    #[DataProvider('verboteneSchluessel')]
    public function test_lehnt_inhaltsnahe_schluessel_ab(string $schluessel): void
    {
        $guard = new JobPayloadGuard;

        $this->expectException(InvalidArgumentException::class);

        $guard->sanitize([$schluessel => 'wert']);
    }

    public function test_lehnt_lange_werte_ab(): void
    {
        $guard = new JobPayloadGuard;

        $this->expectException(InvalidArgumentException::class);

        $guard->sanitize(['hinweis' => str_repeat('a', 200)]);
    }

    public function test_lehnt_verschachtelte_strukturen_ab(): void
    {
        $guard = new JobPayloadGuard;

        $this->expectException(InvalidArgumentException::class);

        $guard->sanitize(['werte' => ['a' => 'b']]);
    }

    public function test_lehnt_mehrzeilige_werte_ab(): void
    {
        $guard = new JobPayloadGuard;

        $this->expectException(InvalidArgumentException::class);

        $guard->sanitize(['hinweis' => "Zeile 1\nZeile 2"]);
    }

    public function test_lehnt_zu_viele_parameter_ab(): void
    {
        $guard = new JobPayloadGuard;

        $payload = [];

        for ($i = 0; $i < 30; $i++) {
            $payload['parameter'.$i] = $i;
        }

        $this->expectException(InvalidArgumentException::class);

        $guard->sanitize($payload);
    }
}
