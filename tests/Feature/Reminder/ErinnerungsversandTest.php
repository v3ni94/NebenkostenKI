<?php

declare(strict_types=1);

namespace Tests\Feature\Reminder;

use App\Application\Reminder\ManageReminderSubscription;
use App\Application\Reminder\ReminderPreferences;
use App\Application\Reminder\ReminderReport;
use App\Application\Reminder\SendReminders;
use App\Enums\BillingRunStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Mail\ErinnerungFolgejahrMail;
use App\Mail\SuppressionGuard;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\ReminderEvent;
use App\Models\ReminderPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Automatische Erinnerungen fuer Folgejahre (Masterprompt 17).
 */
final class ErinnerungsversandTest extends TestCase
{
    use PaketRouten;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registriereRouten();
        Mail::fake();
    }

    /**
     * @return array{user: User, organization: Organization, property: Property}
     */
    private function welt(?User $nutzer = null): array
    {
        $nutzer ??= User::factory()->create();

        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'is_active' => true,
        ]);

        return ['user' => $nutzer, 'organization' => $organisation, 'property' => $objekt];
    }

    private function lauf(): SendReminders
    {
        return app(SendReminders::class);
    }

    private function tag(string $datum): CarbonImmutable
    {
        return CarbonImmutable::parse($datum, 'Europe/Berlin')->startOfDay();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function termine(): array
    {
        return [
            'Q1' => ['2026-01-15', 'Q1'],
            'Q2' => ['2026-04-15', 'Q2'],
            'Q3' => ['2026-07-15', 'Q3'],
            'Dezember' => ['2026-12-01', 'DEZEMBER'],
        ];
    }

    #[DataProvider('termine')]
    public function test_erinnerung_wird_an_allen_vier_terminen_versendet(string $datum, string $fenster): void
    {
        $welt = $this->welt();

        $bericht = $this->lauf()->fuerTag($this->tag($datum));

        $this->assertSame($fenster, $bericht->fenster);
        $this->assertSame(1, $bericht->gesendet);

        Mail::assertSent(ErinnerungFolgejahrMail::class, 1);

        /** @var ReminderEvent $ereignis */
        $ereignis = ReminderEvent::query()->firstOrFail();

        $this->assertSame(ReminderStatus::GESENDET, $ereignis->getAttribute('status'));
        $this->assertSame(2025, $ereignis->getAttribute('billing_year'));
        $this->assertSame($fenster, $ereignis->getAttribute('reminder_window')->value);
        $this->assertSame((string) $welt['property']->getKey(), $ereignis->getAttribute('property_id'));
        $this->assertNotNull($ereignis->getAttribute('email_message_id'));
        $this->assertSame(1, EmailMessage::query()->where('template', 'erinnerung-folgejahr')->count());
    }

    public function test_an_einem_anderen_tag_wird_nichts_versendet(): void
    {
        $this->welt();

        $bericht = $this->lauf()->fuerTag($this->tag('2026-02-01'));

        $this->assertNull($bericht->fenster);
        Mail::assertNothingSent();
        $this->assertSame(0, ReminderEvent::query()->count());
    }

    public function test_mehrfacher_cronlauf_am_selben_tag_erzeugt_keine_dublette(): void
    {
        $this->welt();

        $erster = $this->lauf()->fuerTag($this->tag('2026-01-15'));
        $zweiter = $this->lauf()->fuerTag($this->tag('2026-01-15'));
        $dritter = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        $this->assertSame(1, $erster->gesendet);
        $this->assertSame(0, $zweiter->gesendet);
        $this->assertSame(0, $dritter->gesendet);
        $this->assertSame(1, $zweiter->anzahlUebersprungen(ReminderReport::GRUND_DUBLETTE));

        Mail::assertSent(ErinnerungFolgejahrMail::class, 1);
        $this->assertSame(1, ReminderEvent::query()->count());
    }

    public function test_zweites_fenster_erzeugt_eine_eigene_erinnerung(): void
    {
        $this->welt();

        $this->lauf()->fuerTag($this->tag('2026-01-15'));
        $this->lauf()->fuerTag($this->tag('2026-04-15'));

        Mail::assertSent(ErinnerungFolgejahrMail::class, 2);
        $this->assertSame(2, ReminderEvent::query()->count());
    }

    public function test_keine_erinnerung_bei_finalisiertem_jahreslauf(): void
    {
        $welt = $this->welt();

        BillingRun::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'property_id' => $welt['property']->getKey(),
            'created_by_user_id' => $welt['user']->getKey(),
            'billing_year' => 2025,
            'status' => BillingRunStatus::FINALIZED,
            'finalized_at' => now(),
        ]);

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(0, $bericht->gesendet);
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_FINALISIERT));
        $this->assertSame(0, ReminderEvent::query()->count());
    }

    public function test_entwurf_des_jahreslaufs_verhindert_die_erinnerung_nicht(): void
    {
        $welt = $this->welt();

        BillingRun::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'property_id' => $welt['property']->getKey(),
            'created_by_user_id' => $welt['user']->getKey(),
            'billing_year' => 2025,
            'status' => BillingRunStatus::REVIEW_REQUIRED,
        ]);

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        $this->assertSame(1, $bericht->gesendet);
        Mail::assertSent(ErinnerungFolgejahrMail::class, 1);
    }

    public function test_globale_deaktivierung_verhindert_die_erinnerung(): void
    {
        $welt = $this->welt();

        ReminderPreference::factory()->deactivated()->create([
            'organization_id' => $welt['organization']->getKey(),
            'user_id' => $welt['user']->getKey(),
            'property_id' => null,
        ]);

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_ABGEMELDET));
    }

    public function test_deaktivierung_je_objekt_verhindert_nur_dieses_objekt(): void
    {
        $welt = $this->welt();

        /** @var Property $zweites */
        $zweites = Property::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'created_by_user_id' => $welt['user']->getKey(),
            'is_active' => true,
        ]);

        ReminderPreference::factory()->deactivated()->create([
            'organization_id' => $welt['organization']->getKey(),
            'user_id' => $welt['user']->getKey(),
            'property_id' => $welt['property']->getKey(),
        ]);

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        $this->assertSame(1, $bericht->gesendet);
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_ABGEMELDET));

        /** @var ReminderEvent $ereignis */
        $ereignis = ReminderEvent::query()->firstOrFail();

        $this->assertSame((string) $zweites->getKey(), $ereignis->getAttribute('property_id'));
    }

    public function test_einzelnes_fenster_kann_abgeschaltet_werden(): void
    {
        $welt = $this->welt();

        ReminderPreference::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'user_id' => $welt['user']->getKey(),
            'property_id' => null,
            'is_active' => true,
            'q1_enabled' => false,
        ]);

        $this->assertSame(0, $this->lauf()->fuerTag($this->tag('2026-01-15'))->gesendet);
        $this->assertSame(1, $this->lauf()->fuerTag($this->tag('2026-04-15'))->gesendet);
    }

    public function test_reaktivierung_stellt_die_erinnerung_wieder_her(): void
    {
        $welt = $this->welt();

        /** @var ReminderPreference $einstellung */
        $einstellung = ReminderPreference::factory()->deactivated()->create([
            'organization_id' => $welt['organization']->getKey(),
            'user_id' => $welt['user']->getKey(),
            'property_id' => null,
        ]);

        $this->assertSame(0, $this->lauf()->fuerTag($this->tag('2026-01-15'))->gesendet);

        app(ManageReminderSubscription::class)->reaktivieren($einstellung);

        $this->assertSame(1, $this->lauf()->fuerTag($this->tag('2026-04-15'))->gesendet);
        $this->assertTrue(
            AuditLog::query()->where('action', ManageReminderSubscription::AUDIT_REAKTIVIERUNG)->exists()
        );
    }

    public function test_gesperrtes_konto_erhaelt_keine_erinnerung(): void
    {
        $this->welt(User::factory()->blocked()->create());

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_KONTO_NICHT_AKTIV));
    }

    public function test_unbestaetigtes_konto_erhaelt_keine_erinnerung(): void
    {
        $this->welt(User::factory()->unverified()->create());

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(0, $bericht->gesendet);
    }

    public function test_geloeschtes_konto_erhaelt_keine_erinnerung(): void
    {
        $welt = $this->welt();
        $welt['user']->delete();

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_KONTO_NICHT_AKTIV));
    }

    public function test_inaktives_objekt_erhaelt_keine_erinnerung(): void
    {
        $welt = $this->welt();
        $welt['property']->forceFill(['is_active' => false])->save();

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(0, $bericht->geprueft);
    }

    public function test_gesperrte_adresse_erhaelt_keine_erinnerung_und_wird_protokolliert(): void
    {
        $welt = $this->welt();

        app(SuppressionGuard::class)->suppress(
            (string) $welt['user']->getAttribute('email'),
            EmailSuppressionReason::BOUNCE,
            'test'
        );

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertSame(1, $bericht->anzahlUebersprungen(ReminderReport::GRUND_ADRESSE_GESPERRT));

        /** @var ReminderEvent $ereignis */
        $ereignis = ReminderEvent::query()->firstOrFail();

        $this->assertSame(ReminderStatus::UNTERDRUECKT, $ereignis->getAttribute('status'));
        $this->assertSame('Die Adresse steht auf der Sperrliste.', $ereignis->getAttribute('suppressed_reason'));
        $this->assertTrue(AuditLog::query()->where('action', SendReminders::AUDIT_UNTERDRUECKT)->exists());
    }

    public function test_versand_wird_revisionssicher_protokolliert(): void
    {
        $this->welt();

        $this->lauf()->fuerTag($this->tag('2026-01-15'));

        $this->assertTrue(AuditLog::query()->where('action', SendReminders::AUDIT_GESENDET)->exists());
    }

    public function test_erinnerungen_koennen_zentral_abgeschaltet_werden(): void
    {
        config(['smartabrechnen.reminders.enabled' => false]);

        $this->welt();

        $bericht = $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertNothingSent();
        $this->assertNull($bericht->fenster);
    }

    public function test_mandantentrennung_jeder_nutzer_erhaelt_nur_eigene_objekte(): void
    {
        $ersteWelt = $this->welt();
        $zweiteWelt = $this->welt();

        $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertSent(ErinnerungFolgejahrMail::class, 2);

        foreach (ReminderEvent::query()->get() as $ereignis) {
            $objektId = $ereignis->getAttribute('property_id');
            $nutzerId = $ereignis->getAttribute('user_id');

            $erwartet = $objektId === (string) $ersteWelt['property']->getKey()
                ? (string) $ersteWelt['user']->getKey()
                : (string) $zweiteWelt['user']->getKey();

            $this->assertSame($erwartet, $nutzerId);
        }

        $this->assertSame(
            (string) $ersteWelt['organization']->getKey(),
            ReminderEvent::query()
                ->where('property_id', $ersteWelt['property']->getKey())
                ->value('organization_id')
        );
    }

    public function test_erinnerung_enthaelt_signierten_cta_und_abmeldelink(): void
    {
        $welt = $this->welt();

        $this->lauf()->fuerTag($this->tag('2026-01-15'));

        Mail::assertSent(ErinnerungFolgejahrMail::class, function (ErinnerungFolgejahrMail $mail) use ($welt): bool {
            $daten = $mail->daten();

            $start = is_string($daten['startUrl'] ?? null) ? $daten['startUrl'] : '';
            $abmelden = is_string($daten['abmeldeUrl'] ?? null) ? $daten['abmeldeUrl'] : '';

            $this->assertStringContainsString('signature=', $start);
            $this->assertStringContainsString('expires=', $start);
            $this->assertStringContainsString('/folgejahr/2025', $start);

            $this->assertStringContainsString('signature=', $abmelden);
            $this->assertStringNotContainsString('@', $abmelden);
            $this->assertStringNotContainsString((string) $welt['user']->getKey(), $abmelden);

            return true;
        });
    }

    public function test_erinnerung_legt_eine_objekteinstellung_mit_token_an(): void
    {
        $welt = $this->welt();

        $this->lauf()->fuerTag($this->tag('2026-01-15'));

        $einstellung = app(ReminderPreferences::class)->fuerObjekt($welt['user'], $welt['property']);

        $this->assertInstanceOf(ReminderPreference::class, $einstellung);
        $this->assertSame(
            ReminderPreferences::TOKEN_LAENGE,
            mb_strlen((string) $einstellung->getAttribute('unsubscribe_token'))
        );
    }

    public function test_konsolenbefehl_ist_taggleich_idempotent(): void
    {
        $this->welt();

        $this->artisan('smartabrechnen:send-reminders', ['--date' => '2026-01-15'])
            ->assertSuccessful();
        $this->artisan('smartabrechnen:send-reminders', ['--date' => '2026-01-15'])
            ->assertSuccessful();

        Mail::assertSent(ErinnerungFolgejahrMail::class, 1);
        $this->assertSame(1, ReminderEvent::query()->count());
    }

    public function test_konsolenbefehl_ohne_termin_bleibt_wirkungslos(): void
    {
        $this->welt();

        $this->artisan('smartabrechnen:send-reminders', ['--date' => '2026-03-03'])
            ->expectsOutputToContain('kein Erinnerungstermin')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_dedup_schluessel_trennt_objekt_jahr_und_fenster(): void
    {
        $schluessel = ReminderEvent::buildDeduplicationKey('01JNUTZER', '01JOBJEKT', 2025, ReminderWindow::Q1);

        $this->assertSame('01JNUTZER:01JOBJEKT:2025:Q1', $schluessel);
        $this->assertNotSame(
            $schluessel,
            ReminderEvent::buildDeduplicationKey('01JNUTZER', '01JOBJEKT', 2025, ReminderWindow::Q2)
        );
    }
}
