<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\ReminderWindow;
use App\Mail\DokumentverarbeitungAbgeschlossenMail;
use App\Mail\ErinnerungFolgejahrMail;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\PruefaufgabenOffenMail;
use App\Mail\SignedDownloadLink;
use App\Mail\TransactionalMail;
use App\Mail\VerarbeitungsfehlerMail;
use App\Mail\VorschauBereitMail;
use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Zeitlich begrenzter, kontogebundener Downloadlink (Masterprompt 16, 19).
 *
 * VERBINDLICHE REGEL: Eine finale Mieterabrechnung wird niemals unverschluesselt
 * als Anhang versendet. Stattdessen gilt ausschliesslich dieser Link.
 */
final class SignierterDownloadlinkTest extends TestCase
{
    use RefreshDatabase;

    private function dokument(): GeneratedDocument
    {
        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::factory()->create([
            'kind' => GeneratedDocumentKind::MIETERABRECHNUNG,
            'variant' => GeneratedDocumentVariant::FINAL,
        ]);

        return $dokument;
    }

    public function test_link_ist_signiert_und_enthaelt_keine_kundendaten(): void
    {
        $dokument = $this->dokument();

        $url = app(SignedDownloadLink::class)->fuer($dokument);

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString((string) $dokument->getKey(), $url);
        $this->assertStringNotContainsString('@', $url);
    }

    public function test_link_verwendet_die_frist_aus_der_konfiguration(): void
    {
        config(['smartabrechnen.retention.signed_download_ttl_minutes' => 15]);

        $link = app(SignedDownloadLink::class);

        $this->assertSame(15, $link->gueltigkeitMinuten());

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00'));

        $url = $link->fuer($this->dokument());

        $treffer = [];
        preg_match('/expires=(\d+)/', $url, $treffer);

        $this->assertArrayHasKey(1, $treffer);
        $this->assertSame(
            Carbon::parse('2026-03-04 10:15:00')->getTimestamp(),
            (int) $treffer[1]
        );

        Carbon::setTestNow();
    }

    public function test_abgelaufener_link_wird_abgewiesen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00'));
        config(['smartabrechnen.retention.signed_download_ttl_minutes' => 30]);

        $url = app(SignedDownloadLink::class)->fuer($this->dokument());

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:31:00'));

        // Ohne Anmeldung fuehrt der Aufruf zur Anmeldeseite, mit abgelaufener
        // Signatur bleibt der Zugriff in jedem Fall verwehrt.
        $antwort = $this->get($url);

        $this->assertContains($antwort->getStatusCode(), [302, 403]);

        Carbon::setTestNow();
    }

    public function test_keine_mail_haengt_eine_mieterabrechnung_an(): void
    {
        $abrechnung = $this->dokument();

        /** @var list<TransactionalMail> $mails */
        $mails = [
            new DokumentverarbeitungAbgeschlossenMail('Guten Tag,', 'Objekt', 2025, 4, 'https://beispiel.invalid'),
            new PruefaufgabenOffenMail('Guten Tag,', 'Objekt', 2025, 2, ['Ein Punkt'], 'https://beispiel.invalid'),
            new VorschauBereitMail('Guten Tag,', 'Objekt', 2025, 2, 4980, 'https://beispiel.invalid'),
            new FinalabrechnungenVerfuegbarMail(
                'Guten Tag,',
                'Objekt',
                2025,
                2,
                'https://beispiel.invalid/download',
                30,
                'https://beispiel.invalid'
            ),
            new VerarbeitungsfehlerMail(
                'Guten Tag,',
                'Objekt',
                2025,
                'Ein Wert fehlt.',
                'Bitte laden Sie die Seite erneut hoch.',
                'https://beispiel.invalid'
            ),
            new ErinnerungFolgejahrMail(
                'Guten Tag,',
                'Objekt',
                2025,
                ReminderWindow::Q3,
                'https://beispiel.invalid/start',
                'https://beispiel.invalid/abmelden'
            ),
        ];

        foreach ($mails as $mail) {
            $this->assertSame([], $mail->anhangDokumente(), $mail->template());
            $this->assertSame([], $mail->attachments(), $mail->template());
        }

        // Auch eine unzulaessige Zuweisung ueber die Basisklasse fuehrt nicht zu
        // einem Anhang: attachments() filtert alles ausser der HVM-Rechnung.
        $mitAbrechnung = new class($abrechnung) extends TransactionalMail
        {
            public function __construct(private readonly GeneratedDocument $dokument) {}

            public function template(): string
            {
                return 'test-anhang';
            }

            public function betreff(): string
            {
                return 'Test';
            }

            public function blade(): string
            {
                return 'emails.transaktion.vorschau-bereit';
            }

            /**
             * @return array<string, mixed>
             */
            public function daten(): array
            {
                return [];
            }

            /**
             * @return list<GeneratedDocument>
             */
            public function anhangDokumente(): array
            {
                return [$this->dokument];
            }
        };

        $this->assertSame([], $mitAbrechnung->attachments());
    }
}
