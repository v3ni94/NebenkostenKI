<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\EmailStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\ReminderWindow;
use App\Mail\BounceHandler;
use App\Mail\ErinnerungFolgejahrMail;
use App\Mail\FinalabrechnungenVerfuegbarMail;
use App\Mail\MailDispatcher;
use App\Mail\SuppressionGuard;
use App\Mail\UnzulaessigerAnhangException;
use App\Mail\VorschauBereitMail;
use App\Mail\ZahlungBestaetigtMail;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Versand, Protokoll und Sperrliste (Masterprompt 16, 17.2, 19).
 *
 * Es wird niemals echt versendet. Der Mailer ist immer gefaelscht.
 */
final class MailVersandProtokollTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, organization: Organization, run: BillingRun}
     */
    private function welt(): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        return ['user' => $nutzer, 'organization' => $organisation, 'run' => $lauf];
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

    private function dispatcher(): MailDispatcher
    {
        return app(MailDispatcher::class);
    }

    public function test_versand_wird_in_email_messages_protokolliert(): void
    {
        Mail::fake();
        $welt = $this->welt();

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'Vermieter@Beispiel.invalid',
            nutzer: $welt['user'],
            organizationId: (string) $welt['organization']->getKey(),
            lauf: $welt['run'],
        );

        Mail::assertSent(VorschauBereitMail::class);

        $this->assertSame('vorschau-bereit', $protokoll->getAttribute('template'));
        $this->assertSame('vermieter@beispiel.invalid', $protokoll->getAttribute('recipient_email'));
        $this->assertSame(EmailStatus::GESENDET, $protokoll->getAttribute('status'));
        $this->assertSame(1, $protokoll->getAttribute('attempts'));
        $this->assertNotNull($protokoll->getAttribute('message_id'));
        $this->assertNotNull($protokoll->getAttribute('sent_at'));
        $this->assertSame((string) $welt['run']->getKey(), $protokoll->getAttribute('billing_run_id'));
        $this->assertSame(1, EmailMessage::query()->count());
    }

    public function test_protokoll_enthaelt_keinen_vertraulichen_inhalt(): void
    {
        Mail::fake();
        $welt = $this->welt();

        $protokoll = $this->dispatcher()->send(
            mail: new FinalabrechnungenVerfuegbarMail(
                anrede: 'Guten Tag,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 3,
                downloadUrl: 'https://smart-abrechnen.de/app/downloads/01JTEST?signature=streng-geheim',
                gueltigkeitMinuten: 30,
                portalUrl: 'https://smart-abrechnen.de/app',
            ),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $spalten = $protokoll->getAttributes();

        foreach ($spalten as $wert) {
            if (! is_string($wert)) {
                continue;
            }

            $this->assertStringNotContainsString('signature=', $wert);
            $this->assertStringNotContainsString('geheim', $wert);
        }
    }

    public function test_versand_wird_revisionssicher_protokolliert(): void
    {
        Mail::fake();
        $welt = $this->welt();

        $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
            organizationId: (string) $welt['organization']->getKey(),
        );

        $this->assertTrue(
            AuditLog::query()->where('action', MailDispatcher::AUDIT_GESENDET)->exists()
        );
    }

    public function test_unterdrueckte_adresse_erhaelt_keine_gewoehnliche_mail(): void
    {
        Mail::fake();
        $welt = $this->welt();

        app(SuppressionGuard::class)->suppress(
            'vermieter@beispiel.invalid',
            EmailSuppressionReason::BOUNCE,
            'test'
        );

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        Mail::assertNothingSent();
        $this->assertSame(EmailStatus::UNTERDRUECKT, $protokoll->getAttribute('status'));
        $this->assertSame('ADRESSE_GESPERRT', $protokoll->getAttribute('error_code'));
        $this->assertTrue(AuditLog::query()->where('action', MailDispatcher::AUDIT_UNTERDRUECKT)->exists());
    }

    public function test_kritische_zahlungsmail_wird_trotz_sperre_versendet(): void
    {
        Mail::fake();
        $welt = $this->welt();

        app(SuppressionGuard::class)->suppress(
            'vermieter@beispiel.invalid',
            EmailSuppressionReason::BOUNCE,
            'test'
        );

        $mail = new ZahlungBestaetigtMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 3,
            betragCent: 7470,
            bezahltAm: Carbon::parse('2026-03-04'),
            portalUrl: 'https://smart-abrechnen.de/app/konto',
        );

        $this->assertTrue($mail->istKritisch());

        $protokoll = $this->dispatcher()->send(
            mail: $mail,
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        Mail::assertSent(ZahlungBestaetigtMail::class);
        $this->assertSame(EmailStatus::GESENDET, $protokoll->getAttribute('status'));
    }

    public function test_dauerhafter_zustellfehler_fuehrt_zur_sperre_und_kontohinweis(): void
    {
        $welt = $this->welt();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(
            new RuntimeException('550 5.1.1 Empfänger unbekannt')
        );

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $this->assertSame(EmailStatus::BOUNCED, $protokoll->getAttribute('status'));
        $this->assertSame(1, $protokoll->getAttribute('attempts'));
        $this->assertNotNull($protokoll->getAttribute('failed_at'));

        /** @var EmailSuppression|null $sperre */
        $sperre = EmailSuppression::query()->where('email', 'vermieter@beispiel.invalid')->first();

        $this->assertInstanceOf(EmailSuppression::class, $sperre);
        $this->assertSame(EmailSuppressionReason::BOUNCE, $sperre->getAttribute('reason'));

        $hinweis = app(SuppressionGuard::class)->hinweisFuerKonto('vermieter@beispiel.invalid');

        $this->assertIsString($hinweis);
        $this->assertStringContainsString('nicht zugestellt', $hinweis);
        $this->assertStringNotContainsString('–', $hinweis);

        $this->assertTrue(AuditLog::query()->where('action', BounceHandler::AUDIT_ACTION)->exists());
    }

    /**
     * Absenderseitige Fehler sagen nichts ueber die Empfaengeradresse aus und
     * duerfen sie nie sperren. Die Meldungen entsprechen dem Wortlaut von
     * Symfony Mailer.
     *
     * @return array<string, array{string}>
     */
    public static function absenderseitigeFehler(): array
    {
        return [
            'Postausgangsserver nicht erreichbar, Port 587 in der Meldung' => [
                'Connection could not be established with host "smtp.beispiel.invalid:587": stream_socket_client(): Unable to connect',
            ],
            'falsches Postfachpasswort, Code 535' => [
                'Failed to authenticate on SMTP server with username "kontakt@beispiel.invalid" using the following authenticators: "LOGIN", "PLAIN". '
                .'Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 Authentication credentials invalid"."',
            ],
            'Anmeldung erforderlich, Code 530' => [
                'Expected response code "250" but got code "530", with message "530 5.7.0 Authentication required".',
            ],
            'Verbindung abgebrochen' => [
                'Connection to "smtp.beispiel.invalid:465" has been closed unexpectedly.',
            ],
            'Zeitueberschreitung' => [
                'Connection to "smtp.beispiel.invalid:465" timed out.',
            ],
            'zeitweilige Ablehnung 451' => [
                'Expected response code "250" but got code "451", with message "451 4.7.1 Greylisted, try again later".',
            ],
        ];
    }

    #[DataProvider('absenderseitigeFehler')]
    public function test_absenderseitiger_fehler_sperrt_die_adresse_nicht(string $meldung): void
    {
        $welt = $this->welt();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException($meldung));

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $this->assertSame(EmailStatus::FEHLGESCHLAGEN, $protokoll->getAttribute('status'));
        $this->assertSame(0, EmailSuppression::query()->count(), $meldung);
        $this->assertNull(app(SuppressionGuard::class)->hinweisFuerKonto('vermieter@beispiel.invalid'));
    }

    public function test_ablehnung_des_empfaengers_durch_die_gegenstelle_sperrt_die_adresse(): void
    {
        $welt = $this->welt();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException(
            'Expected response code "250" but got code "550", with message "550 5.1.1 <vermieter@beispiel.invalid>: Recipient address rejected: User unknown".'
        ));

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $this->assertSame(EmailStatus::BOUNCED, $protokoll->getAttribute('status'));
        $this->assertSame(1, EmailSuppression::query()->count());
    }

    public function test_zeitweiliger_fehler_fuehrt_nicht_zur_sperre(): void
    {
        $welt = $this->welt();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(
            new RuntimeException('Verbindung zum Postausgangsserver hat zu lange gedauert')
        );

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $this->assertSame(EmailStatus::FEHLGESCHLAGEN, $protokoll->getAttribute('status'));
        $this->assertSame(0, EmailSuppression::query()->count());
    }

    public function test_fehlerprotokoll_redigiert_zugangsdaten(): void
    {
        config(['mail.mailers.smtp.password' => 'streng-geheimes-kennwort']);

        $welt = $this->welt();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(
            new RuntimeException('Anmeldung fehlgeschlagen mit streng-geheimes-kennwort')
        );

        $protokoll = $this->dispatcher()->send(
            mail: $this->vorschaumail(),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        $meldung = (string) $protokoll->getAttribute('error_message');

        $this->assertStringNotContainsString('streng-geheimes-kennwort', $meldung);
        $this->assertStringContainsString('[redigiert]', $meldung);
    }

    public function test_erinnerung_an_gesperrte_adresse_bleibt_unterdrueckt(): void
    {
        Mail::fake();
        $welt = $this->welt();

        app(SuppressionGuard::class)->suppress(
            'vermieter@beispiel.invalid',
            EmailSuppressionReason::BOUNCE,
            'test'
        );

        $mail = new ErinnerungFolgejahrMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            fenster: ReminderWindow::Q1,
            startUrl: 'https://smart-abrechnen.de/start',
            abmeldeUrl: 'https://smart-abrechnen.de/abmelden',
        );

        $this->assertFalse($mail->istKritisch());

        $protokoll = $this->dispatcher()->send(
            mail: $mail,
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        Mail::assertNothingSent();
        $this->assertSame(EmailStatus::UNTERDRUECKT, $protokoll->getAttribute('status'));
    }

    public function test_sperre_ist_idempotent_und_normalisiert(): void
    {
        $wache = app(SuppressionGuard::class);

        $erste = $wache->suppress('Vermieter@Beispiel.invalid', EmailSuppressionReason::BOUNCE, 'a');
        $zweite = $wache->suppress('vermieter@beispiel.invalid', EmailSuppressionReason::BESCHWERDE, 'b');

        $this->assertSame($erste->getKey(), $zweite->getKey());
        $this->assertSame(1, EmailSuppression::query()->count());
        $this->assertTrue($wache->isSuppressed('VERMIETER@BEISPIEL.INVALID'));

        $this->assertTrue($wache->release('vermieter@beispiel.invalid'));
        $this->assertFalse($wache->isSuppressed('vermieter@beispiel.invalid'));
    }

    public function test_mieterabrechnung_als_anhang_wird_abgewiesen(): void
    {
        Mail::fake();
        $welt = $this->welt();

        /** @var GeneratedDocument $abrechnung */
        $abrechnung = GeneratedDocument::factory()->finalVariant()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $welt['run']->getKey(),
            'kind' => GeneratedDocumentKind::MIETERABRECHNUNG,
        ]);

        $mail = new ZahlungBestaetigtMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 3,
            betragCent: 7470,
            bezahltAm: Carbon::parse('2026-03-04'),
            portalUrl: 'https://smart-abrechnen.de/app/konto',
            rechnung: $abrechnung,
        );

        $this->expectException(UnzulaessigerAnhangException::class);

        try {
            $this->dispatcher()->send(
                mail: $mail,
                empfaenger: 'vermieter@beispiel.invalid',
                nutzer: $welt['user'],
            );
        } finally {
            Mail::assertNothingSent();
            $this->assertSame(0, EmailMessage::query()->count());
        }
    }

    public function test_hvm_rechnung_darf_angehaengt_werden(): void
    {
        Mail::fake();
        $welt = $this->welt();

        /** @var GeneratedDocument $rechnung */
        $rechnung = GeneratedDocument::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $welt['run']->getKey(),
            'kind' => GeneratedDocumentKind::HVM_RECHNUNG,
            'variant' => GeneratedDocumentVariant::FINAL,
            'storage_disk' => 'local',
        ]);

        $mail = new ZahlungBestaetigtMail(
            anrede: 'Guten Tag,',
            objekt: 'Objekt Lindenweg 4',
            jahr: 2025,
            abrechnungen: 3,
            betragCent: 7470,
            bezahltAm: Carbon::parse('2026-03-04'),
            portalUrl: 'https://smart-abrechnen.de/app/konto',
            rechnung: $rechnung,
        );

        $protokoll = $this->dispatcher()->send(
            mail: $mail,
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $welt['user'],
        );

        Mail::assertSent(ZahlungBestaetigtMail::class);
        $this->assertSame(EmailStatus::GESENDET, $protokoll->getAttribute('status'));
        $this->assertCount(1, $mail->attachments());
    }
}
