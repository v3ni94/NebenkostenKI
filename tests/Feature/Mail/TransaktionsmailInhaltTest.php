<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\ReminderWindow;
use App\Mail\DokumentverarbeitungAbgeschlossenMail;
use App\Mail\ErinnerungFolgejahrMail;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\Format;
use App\Mail\HvmRechnungVerfuegbarMail;
use App\Mail\PruefaufgabenOffenMail;
use App\Mail\TransactionalMail;
use App\Mail\VerarbeitungsfehlerMail;
use App\Mail\VorschauBereitMail;
use App\Mail\ZahlungBestaetigtMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aufbau und Sprache der Transaktionsmails (Masterprompt 16, 18).
 *
 * Geprueft wird je Mailtyp:
 *   - Betreff in deutscher Sprache
 *   - HTML-Fassung UND Textfassung vorhanden und gerendert
 *   - kein Gedankenstrich im deutschen Text
 *   - keine Werbung, kein Zaehlpixel, kein externes Bild
 *   - kein Absender im Code, der Absender kommt aus der Mailkonfiguration
 */
final class TransaktionsmailInhaltTest extends TestCase
{
    /**
     * @return array<string, array{0: TransactionalMail}>
     */
    public static function mails(): array
    {
        return [
            'Dokumentverarbeitung abgeschlossen' => [new DokumentverarbeitungAbgeschlossenMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                dokumente: 12,
                portalUrl: 'https://smart-abrechnen.de/app/abrechnungen/01JTESTLAUF',
            )],
            'Pruefaufgaben offen' => [new PruefaufgabenOffenMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                offen: 3,
                themen: ['Vorauszahlungen der Einheit WE 2', 'Verteilerschlüssel für die Gartenpflege'],
                portalUrl: 'https://smart-abrechnen.de/app/abrechnungen/01JTESTLAUF',
            )],
            'Vorschau bereit' => [new VorschauBereitMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 5,
                preisGesamtCent: 12450,
                portalUrl: 'https://smart-abrechnen.de/app/abrechnungen/01JTESTLAUF',
            )],
            'Zahlung bestaetigt' => [new ZahlungBestaetigtMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 5,
                betragCent: 12450,
                bezahltAm: Carbon::parse('2026-03-04'),
                portalUrl: 'https://smart-abrechnen.de/app/konto',
            )],
            'Finalabrechnungen verfuegbar' => [new FinalabrechnungenVerfuegbarMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 5,
                downloadUrl: 'https://smart-abrechnen.de/app/downloads/01JTESTDOK?signature=abc',
                gueltigkeitMinuten: 30,
                portalUrl: 'https://smart-abrechnen.de/app/konto',
            )],
            'HVM-Rechnung verfuegbar' => [new HvmRechnungVerfuegbarMail(
                anrede: 'Guten Tag Frau Rehberg,',
                rechnungsnummer: 'NK-2026-000123',
                bruttoCent: 12450,
                ausgestelltAm: '04.03.2026',
                portalUrl: 'https://smart-abrechnen.de/app/konto',
            )],
            'Verarbeitungsfehler' => [new VerarbeitungsfehlerMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                sachverhalt: 'Aus der Heizkostenabrechnung konnten die Verbrauchswerte nicht gelesen werden.',
                empfehlung: 'Bitte laden Sie die Seite mit der Einzelabrechnung erneut hoch.',
                portalUrl: 'https://smart-abrechnen.de/app/abrechnungen/01JTESTLAUF',
            )],
            'Erinnerung Folgejahr' => [new ErinnerungFolgejahrMail(
                anrede: 'Guten Tag Frau Rehberg,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                fenster: ReminderWindow::Q1,
                startUrl: 'https://smart-abrechnen.de/app/objekte/01JTESTOBJ/folgejahr/2025?signature=abc',
                abmeldeUrl: 'https://smart-abrechnen.de/erinnerungen/abmelden/token?signature=abc',
            )],
        ];
    }

    #[DataProvider('mails')]
    public function test_betreff_ist_deutsch_und_gefuellt(TransactionalMail $mail): void
    {
        $betreff = $mail->betreff();

        $this->assertNotSame('', trim($betreff));
        $this->assertLessThanOrEqual(185, mb_strlen($betreff));
        $this->assertStringNotContainsString('–', $betreff);
        $this->assertStringNotContainsString('—', $betreff);
    }

    #[DataProvider('mails')]
    public function test_html_und_textfassung_bestehen_und_rendern(TransactionalMail $mail): void
    {
        $this->assertTrue(View::exists($mail->blade()), 'HTML-Vorlage fehlt: '.$mail->blade());
        $this->assertTrue(View::exists($mail->blade().'-text'), 'Textfassung fehlt: '.$mail->blade());

        $html = View::make($mail->blade(), $mail->daten())->render();
        $text = View::make($mail->blade().'-text', $mail->daten())->render();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('Hausverwaltung Müller GmbH', $html);
        $this->assertStringContainsString('Mit freundlichen Grüßen', $html);

        $this->assertStringNotContainsString('<html', $text);
        $this->assertStringContainsString('SMART ABRECHNEN', $text);
        $this->assertStringContainsString('Mit freundlichen Grüßen', $text);
        $this->assertGreaterThan(200, mb_strlen(trim($text)));
    }

    #[DataProvider('mails')]
    public function test_kein_gedankenstrich_im_deutschen_text(TransactionalMail $mail): void
    {
        foreach ([$mail->blade(), $mail->blade().'-text'] as $vorlage) {
            $inhalt = View::make($vorlage, $mail->daten())->render();

            $this->assertStringNotContainsString('–', $inhalt, 'Gedankenstrich in '.$vorlage);
            $this->assertStringNotContainsString('—', $inhalt, 'Gedankenstrich in '.$vorlage);
            $this->assertStringNotContainsString(' - ', $inhalt, 'Gedankenstrich in '.$vorlage);
        }
    }

    #[DataProvider('mails')]
    public function test_kein_zaehlpixel_und_kein_externes_bild(TransactionalMail $mail): void
    {
        $html = View::make($mail->blade(), $mail->daten())->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('utm_', $html);
        $this->assertStringNotContainsString('pixel', $html);
    }

    #[DataProvider('mails')]
    public function test_absender_wird_nicht_im_code_gesetzt(TransactionalMail $mail): void
    {
        $this->assertSame([], $mail->from);
        $this->assertSame([], $mail->replyTo);
        $this->assertNotSame('', $mail->template());
    }

    public function test_templateschluessel_sind_eindeutig(): void
    {
        $schluessel = [];

        foreach (self::mails() as $eintrag) {
            $schluessel[] = $eintrag[0]->template();
        }

        $this->assertSame($schluessel, array_values(array_unique($schluessel)));
        $this->assertCount(8, $schluessel);
    }

    public function test_betraege_und_datum_haben_deutsches_format(): void
    {
        $this->assertSame('1.234,56 EUR', Format::betrag(123456));
        $this->assertSame('0,00 EUR', Format::betrag(0));
        $this->assertSame('-12,05 EUR', Format::betrag(-1205));
        $this->assertSame('04.03.2026', Format::datum(Carbon::parse('2026-03-04')));
    }

    public function test_erinnerung_im_dezember_nennt_die_frist(): void
    {
        $mail = new ErinnerungFolgejahrMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            fenster: ReminderWindow::DEZEMBER,
            startUrl: 'https://smart-abrechnen.de/start',
            abmeldeUrl: 'https://smart-abrechnen.de/abmelden',
        );

        $this->assertStringContainsString('Frist', $mail->betreff());

        $text = View::make($mail->blade().'-text', $mail->daten())->render();

        $this->assertStringContainsString('Abrechnungsfrist', $text);
        $this->assertStringContainsString('keine Rechtsberatung', $text);
    }

    public function test_erinnerung_enthaelt_abmeldelink_in_beiden_fassungen(): void
    {
        $mail = new ErinnerungFolgejahrMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            fenster: ReminderWindow::Q2,
            startUrl: 'https://smart-abrechnen.de/start-2025',
            abmeldeUrl: 'https://smart-abrechnen.de/abmelden-xyz',
        );

        foreach ([$mail->blade(), $mail->blade().'-text'] as $vorlage) {
            $inhalt = View::make($vorlage, $mail->daten())->render();

            $this->assertStringContainsString('https://smart-abrechnen.de/abmelden-xyz', $inhalt);
            $this->assertStringContainsString('https://smart-abrechnen.de/start-2025', $inhalt);
        }
    }

    public function test_finalabrechnung_nennt_downloadlink_und_frist_statt_anhang(): void
    {
        $mail = new FinalabrechnungenVerfuegbarMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 4,
            downloadUrl: 'https://smart-abrechnen.de/app/downloads/01JTESTDOK?signature=abc',
            gueltigkeitMinuten: 30,
            portalUrl: 'https://smart-abrechnen.de/app/konto',
        );

        $text = View::make($mail->blade().'-text', $mail->daten())->render();

        $this->assertStringContainsString('nicht als E-Mail-Anhang', $text);
        $this->assertStringContainsString('30 Minuten gültig', $text);
        $this->assertSame([], $mail->anhangDokumente());
    }
}
