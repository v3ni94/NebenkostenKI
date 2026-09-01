<?php

declare(strict_types=1);

namespace Tests\Feature\Reminder;

use App\Application\Reminder\ManageReminderSubscription;
use App\Application\Reminder\ReminderLinks;
use App\Application\Reminder\ReminderPreferences;
use App\Enums\EmailSuppressionReason;
use App\Mail\SuppressionGuard;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Sicherer Abmeldelink ohne vorherige Anmeldung (Masterprompt 17.2).
 */
final class AbmeldelinkTest extends TestCase
{
    use PaketRouten;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registriereRouten();
    }

    /**
     * @return array{user: User, organization: Organization, property: Property, preference: ReminderPreference}
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

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        $einstellung = app(ReminderPreferences::class)->objektEinstellung($nutzer, $objekt);

        return [
            'user' => $nutzer,
            'organization' => $organisation,
            'property' => $objekt,
            'preference' => $einstellung,
        ];
    }

    public function test_abmeldung_funktioniert_ohne_anmeldung(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->abmeldeUrl($welt['preference']);

        $this->assertGuest();

        $antwort = $this->get($url);

        $antwort->assertRedirect(route('site.home'));
        $antwort->assertSessionHas('status');

        $welt['preference']->refresh();

        $this->assertFalse((bool) $welt['preference']->getAttribute('is_active'));
        $this->assertNotNull($welt['preference']->getAttribute('deactivated_at'));
    }

    public function test_abmeldelink_ist_signiert_und_ohne_kundendaten(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->abmeldeUrl($welt['preference']);

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString((string) $welt['user']->getKey(), $url);
        $this->assertStringNotContainsString((string) $welt['property']->getKey(), $url);
        $this->assertStringNotContainsString((string) $welt['user']->getAttribute('email'), $url);
        $this->assertStringNotContainsString('@', $url);
    }

    public function test_veraenderter_link_wird_abgewiesen(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->abmeldeUrl($welt['preference']);

        $this->get($url.'X')->assertForbidden();

        $welt['preference']->refresh();

        $this->assertTrue((bool) $welt['preference']->getAttribute('is_active'));
    }

    public function test_link_ohne_signatur_wird_abgewiesen(): void
    {
        $welt = $this->welt();

        $token = (string) $welt['preference']->getAttribute('unsubscribe_token');

        $this->get('/erinnerungen/abmelden/'.$token)->assertForbidden();
    }

    public function test_unbekannter_token_fuehrt_zu_404(): void
    {
        $this->welt();

        $url = URL::signedRoute('erinnerungen.abmelden', [
            'token' => str_repeat('a', ReminderPreferences::TOKEN_LAENGE),
        ]);

        $this->get($url)->assertNotFound();
    }

    public function test_abmeldung_wird_protokolliert(): void
    {
        $welt = $this->welt();

        $this->get(app(ReminderLinks::class)->abmeldeUrl($welt['preference']));

        $this->assertTrue(
            AuditLog::query()->where('action', ManageReminderSubscription::AUDIT_ABMELDUNG)->exists()
        );
    }

    public function test_reaktivierung_funktioniert_ohne_anmeldung(): void
    {
        $welt = $this->welt();

        app(ManageReminderSubscription::class)->abmelden($welt['preference']);

        $antwort = $this->get(app(ReminderLinks::class)->aktivierungsUrl($welt['preference']));

        $antwort->assertRedirect(route('site.home'));

        $welt['preference']->refresh();

        $this->assertTrue((bool) $welt['preference']->getAttribute('is_active'));
        $this->assertNotNull($welt['preference']->getAttribute('reactivated_at'));
        $this->assertNull($welt['preference']->getAttribute('deactivated_at'));
        $this->assertTrue(
            AuditLog::query()->where('action', ManageReminderSubscription::AUDIT_REAKTIVIERUNG)->exists()
        );
    }

    public function test_reaktivierung_hebt_eine_sperre_nach_abmeldung_auf(): void
    {
        $welt = $this->welt();
        $adresse = (string) $welt['user']->getAttribute('email');

        app(SuppressionGuard::class)->suppress($adresse, EmailSuppressionReason::ABMELDUNG, 'test');

        app(ManageReminderSubscription::class)->reaktivieren($welt['preference']);

        $this->assertFalse(app(SuppressionGuard::class)->isSuppressed($adresse));
    }

    public function test_reaktivierung_haelt_eine_sperre_nach_zustellfehler(): void
    {
        $welt = $this->welt();
        $adresse = (string) $welt['user']->getAttribute('email');

        app(SuppressionGuard::class)->suppress($adresse, EmailSuppressionReason::BOUNCE, 'test');

        app(ManageReminderSubscription::class)->reaktivieren($welt['preference']);

        $this->assertTrue(app(SuppressionGuard::class)->isSuppressed($adresse));
    }

    public function test_abmeldung_nennt_das_betroffene_objekt(): void
    {
        $welt = $this->welt();

        $bezeichnung = app(ManageReminderSubscription::class)->objektbezeichnung($welt['preference']);

        $this->assertSame((string) $welt['property']->getAttribute('label'), $bezeichnung);
    }
}
