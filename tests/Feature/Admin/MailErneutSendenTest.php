<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\EmailStatus;
use App\Http\Controllers\Admin\CommunicationController;
use App\Mail\MailDispatcher;
use App\Mail\VorschauBereitMail;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Mockery;
use RuntimeException;

/**
 * Adminhandlung "Erneut senden" fuer zeitweilig gescheiterte Nachrichten
 * (Befund N20).
 */
final class MailErneutSendenTest extends AdminTestCase
{
    private MailManager $mailManager;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app('mail.manager');
        self::assertInstanceOf(MailManager::class, $manager);
        $this->mailManager = $manager;
    }

    private function postausgangWiederErreichbar(): void
    {
        Mail::swap(new MailFake($this->mailManager));
    }

    private function gescheiterteNachricht(): EmailMessage
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $attrappe = Mockery::mock(MailManager::class);
        $attrappe->shouldReceive('to')->andReturnSelf();
        $attrappe->shouldReceive('send')->andThrow(new RuntimeException(
            'Connection could not be established with host "smtp.beispiel.invalid:587": stream_socket_client(): Unable to connect',
        ));
        Mail::swap($attrappe);

        return app(MailDispatcher::class)->send(
            mail: new VorschauBereitMail(
                anrede: 'Guten Tag,',
                objekt: 'Objekt Lindenweg 4',
                jahr: 2025,
                abrechnungen: 3,
                preisGesamtCent: 7470,
                portalUrl: 'https://smart-abrechnen.de/app',
            ),
            empfaenger: 'vermieter@beispiel.invalid',
            nutzer: $nutzer,
        );
    }

    public function test_die_uebersicht_bietet_fuer_eine_wiederholbare_nachricht_das_erneute_senden_an(): void
    {
        $nachricht = $this->gescheiterteNachricht();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kommunikation');

        $antwort->assertOk();
        $antwort->assertSee('Erneut senden');
        $antwort->assertSee(route('admin.kommunikation.nachricht.erneut', $nachricht), false);
    }

    public function test_der_admin_versendet_eine_gescheiterte_nachricht_erneut_mit_audit(): void
    {
        $nachricht = $this->gescheiterteNachricht();
        $admin = $this->interneKennung();

        $this->postausgangWiederErreichbar();

        $this->actingAs($admin)
            ->post(route('admin.kommunikation.nachricht.erneut', $nachricht))
            ->assertRedirect(route('admin.kommunikation'))
            ->assertSessionHas('status');

        Mail::assertSent(VorschauBereitMail::class, 1);

        $nachricht->refresh();

        self::assertSame(EmailStatus::GESENDET, $nachricht->getAttribute('status'));
        self::assertSame(2, $nachricht->getAttribute('attempts'));
        self::assertNull($nachricht->getAttribute('retry_payload'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => CommunicationController::AUDIT_ERNEUT_SENDEN_ANGEFORDERT,
            'subject_id' => $nachricht->getKey(),
            'actor_user_id' => $admin->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => MailDispatcher::AUDIT_ERNEUT_GESENDET,
            'subject_id' => $nachricht->getKey(),
        ]);
    }

    public function test_eine_bereits_gesendete_nachricht_wird_nicht_erneut_versendet(): void
    {
        /** @var EmailMessage $nachricht */
        $nachricht = EmailMessage::factory()->create();

        $this->postausgangWiederErreichbar();

        $this->actingAs($this->interneKennung())
            ->post(route('admin.kommunikation.nachricht.erneut', $nachricht))
            ->assertRedirect(route('admin.kommunikation'))
            ->assertSessionHas('hinweis');

        Mail::assertNothingSent();
        self::assertSame(1, $nachricht->refresh()->getAttribute('attempts'));
    }

    public function test_ein_kunde_erreicht_die_handlung_nicht(): void
    {
        $nachricht = $this->gescheiterteNachricht();
        $kunde = $this->kunde();

        $this->postausgangWiederErreichbar();

        $this->actingAs($kunde['user'])
            ->post(route('admin.kommunikation.nachricht.erneut', $nachricht))
            ->assertNotFound();

        Mail::assertNothingSent();
        self::assertSame(EmailStatus::FEHLGESCHLAGEN, $nachricht->refresh()->getAttribute('status'));
    }
}
