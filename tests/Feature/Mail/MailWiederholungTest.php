<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\EmailStatus;
use App\Enums\EmailSuppressionReason;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\MailDispatcher;
use App\Mail\SuppressionGuard;
use App\Mail\VorschauBereitMail;
use App\Mail\WiederholungNichtMoeglichException;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Wiederholung zeitweilig gescheiterter Transaktionsmails (Befund N20).
 *
 * Ein Ausfall des Postausgangs darf eine Nachricht nicht endgueltig
 * verschlucken. Wiederholt wird hoechstens dreimal innerhalb von 24 Stunden,
 * nie bei dauerhafter Unzustellbarkeit und nie an eine gesperrte Adresse.
 */
final class MailWiederholungTest extends TestCase
{
    use RefreshDatabase;

    private const string ADRESSE = 'vermieter@beispiel.invalid';

    private MailManager $mailManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Der echte Manager wird vor jeder Attrappe festgehalten, damit der
        // Postausgang im Test spaeter wieder "erreichbar" gemacht werden kann.
        $manager = app('mail.manager');
        $this->assertInstanceOf(MailManager::class, $manager);
        $this->mailManager = $manager;
    }

    /**
     * Der Postausgang antwortet mit dem angegebenen Fehler.
     */
    private function postausgangScheitertMit(Throwable $fehler): void
    {
        $attrappe = Mockery::mock(MailManager::class);
        $attrappe->shouldReceive('to')->andReturnSelf();
        $attrappe->shouldReceive('send')->andThrow($fehler);

        Mail::swap($attrappe);
    }

    /**
     * Der Postausgang ist wieder erreichbar; der Versand wird aufgezeichnet.
     */
    private function postausgangWiederErreichbar(): void
    {
        Mail::swap(new MailFake($this->mailManager));
    }

    private function vorschaumail(): VorschauBereitMail
    {
        return new VorschauBereitMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 3,
            preisGesamtCent: 7470,
            portalUrl: 'https://smart-abrechnen.de/app',
        );
    }

    /**
     * Ein Versand, der an einem zeitweiligen Fehler des Postausgangs scheitert.
     */
    private function gescheiterterVersand(?VorschauBereitMail $mail = null): EmailMessage
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $this->postausgangScheitertMit(new RuntimeException(
            'Connection could not be established with host "smtp.beispiel.invalid:587": stream_socket_client(): Unable to connect',
        ));

        $protokoll = app(MailDispatcher::class)->send(
            mail: $mail ?? $this->vorschaumail(),
            empfaenger: self::ADRESSE,
            nutzer: $nutzer,
        );

        $this->assertSame(EmailStatus::FEHLGESCHLAGEN, $protokoll->getAttribute('status'));

        return $protokoll;
    }

    public function test_ein_zeitweiliger_fehler_legt_einen_verschluesselten_wiederholungspuffer_an(): void
    {
        $protokoll = $this->gescheiterterVersand(new VorschauBereitMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 3,
            preisGesamtCent: 7470,
            portalUrl: 'https://smart-abrechnen.de/app/geheimer-pfad',
        ));

        $this->assertTrue(app(MailDispatcher::class)->istWiederholbar($protokoll));

        // Der Puffer liegt nur verschluesselt in der Datenbank.
        $roh = DB::table('email_messages')->where('id', $protokoll->getKey())->value('retry_payload');

        $this->assertIsString($roh);
        $this->assertStringNotContainsString('geheimer-pfad', $roh);
        $this->assertStringNotContainsString('VorschauBereitMail', $roh);
    }

    public function test_der_zeitplan_versendet_eine_gescheiterte_nachricht_erneut(): void
    {
        $protokoll = $this->gescheiterterVersand();

        // Der Postausgang ist wieder erreichbar.
        $this->postausgangWiederErreichbar();

        $this->artisan('smartabrechnen:retry-failed-emails')
            ->expectsOutputToContain('Erneut versendet: 1')
            ->assertSuccessful();

        Mail::assertSent(VorschauBereitMail::class, 1);

        $protokoll->refresh();

        $this->assertSame(EmailStatus::GESENDET, $protokoll->getAttribute('status'));
        $this->assertSame(2, $protokoll->getAttribute('attempts'));
        $this->assertNotNull($protokoll->getAttribute('sent_at'));
        $this->assertNull($protokoll->getAttribute('error_code'));
        $this->assertNull($protokoll->getAttribute('retry_payload'));
        $this->assertSame(1, EmailMessage::query()->count(), 'Die Wiederholung erzeugt kein zweites Protokoll.');
        $this->assertTrue(AuditLog::query()->where('action', MailDispatcher::AUDIT_ERNEUT_GESENDET)->exists());
    }

    public function test_nach_der_hoechstzahl_der_versuche_wird_nicht_mehr_wiederholt(): void
    {
        $protokoll = $this->gescheiterterVersand();

        // Der Postausgang bleibt gestoert: zweiter und dritter Versuch scheitern.
        $this->artisan('smartabrechnen:retry-failed-emails')->assertSuccessful();
        $this->artisan('smartabrechnen:retry-failed-emails')->assertSuccessful();

        $protokoll->refresh();

        $this->assertSame(EmailStatus::FEHLGESCHLAGEN, $protokoll->getAttribute('status'));
        $this->assertSame(MailDispatcher::MAX_VERSUCHE, $protokoll->getAttribute('attempts'));
        $this->assertNull($protokoll->getAttribute('retry_payload'), 'Nach dem letzten Versuch braucht es keinen Puffer mehr.');
        $this->assertFalse(app(MailDispatcher::class)->istWiederholbar($protokoll));

        // Ein weiterer Lauf laesst die Nachricht unangetastet.
        $this->postausgangWiederErreichbar();
        $this->artisan('smartabrechnen:retry-failed-emails')
            ->expectsOutputToContain('Erneut versendet: 0')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(MailDispatcher::MAX_VERSUCHE, $protokoll->refresh()->getAttribute('attempts'));
    }

    public function test_nach_ablauf_des_fensters_wird_nicht_wiederholt_und_der_puffer_geleert(): void
    {
        $protokoll = $this->gescheiterterVersand();

        $this->travel(MailDispatcher::WIEDERHOLUNGSFENSTER_STUNDEN + 1)->hours();

        $this->postausgangWiederErreichbar();
        $this->artisan('smartabrechnen:retry-failed-emails')
            ->expectsOutputToContain('Puffer bereinigt: 1')
            ->assertSuccessful();

        Mail::assertNothingSent();

        $protokoll->refresh();

        $this->assertSame(EmailStatus::FEHLGESCHLAGEN, $protokoll->getAttribute('status'));
        $this->assertSame(1, $protokoll->getAttribute('attempts'));
        $this->assertNull($protokoll->getAttribute('retry_payload'));
    }

    public function test_eine_dauerhaft_unzustellbare_nachricht_wird_nicht_wiederholt(): void
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $this->postausgangScheitertMit(new RuntimeException(
            'Expected response code "250" but got code "550", with message "550 5.1.1 <vermieter@beispiel.invalid>: Recipient address rejected: User unknown".',
        ));

        $protokoll = app(MailDispatcher::class)->send(
            mail: $this->vorschaumail(),
            empfaenger: self::ADRESSE,
            nutzer: $nutzer,
        );

        $this->assertSame(EmailStatus::BOUNCED, $protokoll->getAttribute('status'));
        $this->assertNull($protokoll->getAttribute('retry_payload'));

        $this->expectException(WiederholungNichtMoeglichException::class);

        app(MailDispatcher::class)->erneutSenden($protokoll);
    }

    public function test_eine_inzwischen_gesperrte_adresse_erhaelt_keine_wiederholung(): void
    {
        $protokoll = $this->gescheiterterVersand();

        app(SuppressionGuard::class)->suppress(self::ADRESSE, EmailSuppressionReason::BOUNCE, 'test');

        $this->postausgangWiederErreichbar();
        $this->artisan('smartabrechnen:retry-failed-emails')->assertSuccessful();

        Mail::assertNothingSent();

        $protokoll->refresh();

        $this->assertSame(EmailStatus::UNTERDRUECKT, $protokoll->getAttribute('status'));
        $this->assertSame('ADRESSE_GESPERRT', $protokoll->getAttribute('error_code'));
        $this->assertNull($protokoll->getAttribute('retry_payload'));
        $this->assertSame(1, EmailSuppression::query()->count());
    }

    public function test_die_wiederholung_bleibt_bei_dauerhafter_ablehnung_bei_der_sperre(): void
    {
        $protokoll = $this->gescheiterterVersand();

        // Beim zweiten Versuch lehnt die Gegenstelle den Empfaenger dauerhaft ab.
        $this->postausgangScheitertMit(new RuntimeException('550 5.1.1 Empfänger unbekannt'));

        app(MailDispatcher::class)->erneutSenden($protokoll);

        $protokoll->refresh();

        $this->assertSame(EmailStatus::BOUNCED, $protokoll->getAttribute('status'));
        $this->assertSame(2, $protokoll->getAttribute('attempts'));
        $this->assertNull($protokoll->getAttribute('retry_payload'));
        $this->assertSame(1, EmailSuppression::query()->where('email', self::ADRESSE)->count());
    }

    public function test_die_wiederholung_bewahrt_den_zulaessigen_anhangweg(): void
    {
        // Eine Nachricht mit Downloadlink wird aus dem Puffer unveraendert
        // wiederhergestellt, inklusive der Vorlagendaten.
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $this->postausgangScheitertMit(new RuntimeException('Connection to "smtp.beispiel.invalid:465" timed out.'));

        $protokoll = app(MailDispatcher::class)->send(
            mail: new FinalabrechnungenVerfuegbarMail(
                anrede: 'Guten Tag,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 3,
                downloadUrl: 'https://smart-abrechnen.de/app/downloads/01JTEST?signature=streng-geheim',
                gueltigkeitMinuten: 30,
                portalUrl: 'https://smart-abrechnen.de/app',
            ),
            empfaenger: self::ADRESSE,
            nutzer: $nutzer,
        );

        $this->postausgangWiederErreichbar();

        app(MailDispatcher::class)->erneutSenden($protokoll);

        Mail::assertSent(FinalabrechnungenVerfuegbarMail::class, static function (FinalabrechnungenVerfuegbarMail $mail): bool {
            return ($mail->daten()['downloadUrl'] ?? null) === 'https://smart-abrechnen.de/app/downloads/01JTEST?signature=streng-geheim'
                && $mail->template() === 'finalabrechnungen-verfuegbar';
        });
    }
}
