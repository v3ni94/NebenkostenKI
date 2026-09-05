<?php

declare(strict_types=1);

namespace Tests\Feature\FollowUpYear;

use App\Application\Reminder\ReminderLinks;
use App\Enums\BillingRunStatus;
use App\Enums\OrganizationRole;
use App\Models\BillingRun;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reminder\PaketRouten;
use Tests\TestCase;

/**
 * Folgejahres-CTA aus der Erinnerungsmail.
 *
 * Der Link ist zeitlich begrenzt signiert, enthaelt keine Kundendaten und
 * ersetzt die Anmeldung nicht. Ein fremdes Objekt fuehrt zu 404.
 */
final class FolgejahresCtaTest extends TestCase
{
    use PaketRouten;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registriereRouten();
    }

    /**
     * @return array{user: User, organization: Organization, property: Property, run: BillingRun}
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

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'billing_year' => 2025,
            'status' => BillingRunStatus::FINALIZED,
            'finalized_at' => now(),
        ]);

        return ['user' => $nutzer, 'organization' => $organisation, 'property' => $objekt, 'run' => $lauf];
    }

    public function test_cta_enthaelt_keine_kundendaten_und_ist_signiert(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2025);

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringNotContainsString((string) $welt['user']->getKey(), $url);
        $this->assertStringNotContainsString('@', $url);
    }

    public function test_cta_oeffnet_den_vorbereiteten_folgejahreslauf(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        $antwort = $this->actingAs($welt['user'])->get($url);

        /** @var BillingRun|null $neu */
        $neu = BillingRun::query()
            ->where('property_id', $welt['property']->getKey())
            ->where('billing_year', 2026)
            ->first();

        $this->assertInstanceOf(BillingRun::class, $neu);
        $antwort->assertRedirect(route('portal.abrechnungen.show', ['billingRun' => $neu->getKey()]));
        $antwort->assertSessionHas('status');
    }

    public function test_zweiter_aufruf_legt_keinen_zweiten_lauf_an(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        $this->actingAs($welt['user'])->get($url);
        $this->actingAs($welt['user'])->get($url);

        $this->assertSame(
            1,
            BillingRun::query()
                ->where('property_id', $welt['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );
    }

    public function test_cta_ohne_anmeldung_fuehrt_nicht_zum_lauf(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        $this->get($url)->assertRedirect();

        $this->assertSame(
            0,
            BillingRun::query()
                ->where('property_id', $welt['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );
    }

    public function test_eine_nur_lesende_rolle_legt_ueber_den_cta_keinen_lauf_an(): void
    {
        $welt = $this->welt();

        /** @var User $leser */
        $leser = User::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $welt['organization']->getKey(),
            'user_id' => $leser->getKey(),
            'role' => OrganizationRole::READ_ONLY,
            'joined_at' => now(),
        ]);

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        // Dieselbe Policy wie bei der regulaeren Anlage: kein Schreibrecht,
        // kein Lauf. 404 statt 403, damit die Existenz nicht bestaetigt wird.
        $this->actingAs($leser)->get($url)->assertNotFound();

        $this->assertSame(
            0,
            BillingRun::query()
                ->where('property_id', $welt['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );
    }

    public function test_fremdes_objekt_fuehrt_zu_404(): void
    {
        $welt = $this->welt();
        $fremd = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($fremd['property'], 2026);

        $this->actingAs($welt['user'])->get($url)->assertNotFound();

        $this->assertSame(
            0,
            BillingRun::query()
                ->where('property_id', $fremd['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );
    }

    public function test_abgelaufener_cta_wird_abgewiesen(): void
    {
        $welt = $this->welt();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        Carbon::setTestNow(Carbon::now()->addDays(ReminderLinks::CTA_GUELTIGKEIT_TAGE + 1));

        $this->actingAs($welt['user'])->get($url)->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_ohne_abgeschlossenen_vorjahreslauf_leitet_der_cta_zur_anlage(): void
    {
        $welt = $this->welt();
        $welt['run']->forceFill(['status' => BillingRunStatus::CANCELLED])->save();

        $url = app(ReminderLinks::class)->folgejahrUrl($welt['property'], 2026);

        $antwort = $this->actingAs($welt['user'])->get($url);

        $antwort->assertRedirect(
            route('portal.abrechnungen.create', ['property' => $welt['property']->getKey()])
        );
        $antwort->assertSessionHas('status');
    }
}
