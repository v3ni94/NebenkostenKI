<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Account\EmailVerification;
use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\Storage\SignedDownloadUrlFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Auth\Concerns\SimuliertMailausfall;
use Tests\TestCase;

/**
 * E-Mail-Verifizierung.
 *
 * App\Models\User implementiert MustVerifyEmail nicht, der Ablauf arbeitet
 * deshalb ueber eine eigene signierte Route und das Gate email-verified.
 */
final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;
    use SimuliertMailausfall;

    /**
     * @return array{user: User, organization: Organization}
     */
    private function welt(bool $verifiziert): array
    {
        /** @var User $nutzer */
        $nutzer = $verifiziert
            ? User::factory()->create()
            : User::factory()->unverified()->create();

        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        return ['user' => $nutzer, 'organization' => $organisation];
    }

    private function lauf(Organization $organisation, User $nutzer): BillingRun
    {
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'status' => BillingRunStatus::PREVIEW_READY,
        ]);

        return $lauf;
    }

    public function test_hinweisseite_zeigt_die_hinterlegte_adresse(): void
    {
        $welt = $this->welt(false);

        $antwort = $this->actingAs($welt['user'])->get(route('verification.notice'));

        $antwort->assertOk();
        $antwort->assertSee('Bitte bestätigen Sie Ihre E-Mail-Adresse');
        $antwort->assertSee((string) $welt['user']->getAttribute('email'));
    }

    public function test_bestaetigter_nutzer_wird_von_der_hinweisseite_weitergeleitet(): void
    {
        $welt = $this->welt(true);

        $antwort = $this->actingAs($welt['user'])->get(route('verification.notice'));

        $antwort->assertRedirect(route('portal.dashboard'));
    }

    public function test_signierter_link_bestaetigt_die_adresse(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $antwort = $this->actingAs($welt['user'])->get($verifikation->signedUrl($welt['user']));

        $antwort->assertRedirect(route('portal.dashboard'));

        $frisch = $welt['user']->fresh();
        self::assertInstanceOf(User::class, $frisch);
        self::assertNotNull($frisch->getAttribute('email_verified_at'));
        self::assertSame(UserStatus::AKTIV, $frisch->getAttribute('status'));
    }

    public function test_bestaetigung_schreibt_einen_revisionseintrag(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $this->actingAs($welt['user'])->get($verifikation->signedUrl($welt['user']));

        self::assertTrue(
            AuditLog::query()
                ->where('action', 'account.email_verified')
                ->where('actor_user_id', $welt['user']->getKey())
                ->exists()
        );
    }

    public function test_link_mit_falschem_hash_wird_abgewiesen(): void
    {
        $welt = $this->welt(false);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['user' => $welt['user']->getKey(), 'hash' => sha1('fremde@adresse.invalid')]
        );

        $antwort = $this->actingAs($welt['user'])->get($url);

        $antwort->assertForbidden();
        self::assertNull($welt['user']->fresh()?->getAttribute('email_verified_at'));
    }

    public function test_abgelaufener_link_wird_abgewiesen(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $url = $verifikation->signedUrl($welt['user']);

        $this->travel(EmailVerification::LINK_GUELTIGKEIT_MINUTEN + 5)->minutes();

        $antwort = $this->actingAs($welt['user'])->get($url);

        $antwort->assertForbidden();
        self::assertNull($welt['user']->fresh()?->getAttribute('email_verified_at'));
    }

    public function test_unsignierter_link_wird_abgewiesen(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $antwort = $this->actingAs($welt['user'])->get(route('verification.verify', [
            'user' => $welt['user']->getKey(),
            'hash' => $verifikation->hash($welt['user']),
        ]));

        $antwort->assertForbidden();
    }

    public function test_bestaetigungslink_hebt_eine_sperre_nicht_auf(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $url = $verifikation->signedUrl($welt['user']);

        // Sperre nach dem Versand des Links, etwa durch den Adminbereich.
        $welt['user']->forceFill(['status' => UserStatus::GESPERRT])->save();

        $this->get($url)->assertRedirect(route('login'));

        $frisch = $welt['user']->fresh();
        self::assertInstanceOf(User::class, $frisch);
        self::assertSame(UserStatus::GESPERRT, $frisch->getAttribute('status'));
        self::assertNotNull($frisch->getAttribute('email_verified_at'));
    }

    public function test_bestaetigungslink_hebt_eine_loeschvormerkung_nicht_auf(): void
    {
        $welt = $this->welt(false);
        $welt['user']->forceFill(['status' => UserStatus::GELOESCHT])->save();

        $this->get(app(EmailVerification::class)->signedUrl($welt['user']));

        self::assertSame(UserStatus::GELOESCHT, $welt['user']->fresh()?->getAttribute('status'));
    }

    public function test_ein_mailausfall_beim_erneuten_versand_fuehrt_nicht_zu_einer_fehlerseite(): void
    {
        $this->simuliereMailausfall();
        $welt = $this->welt(false);

        $antwort = $this->actingAs($welt['user'])
            ->from(route('verification.notice'))
            ->post(route('verification.send'));

        $antwort->assertRedirect(route('verification.notice'));
        self::assertStringContainsString('konnte gerade nicht versendet werden', (string) session('status'));
    }

    public function test_erneuter_versand_ist_moeglich(): void
    {
        Notification::fake();
        $welt = $this->welt(false);

        $antwort = $this->actingAs($welt['user'])
            ->from(route('verification.notice'))
            ->post(route('verification.send'));

        $antwort->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($welt['user'], VerifyEmailAddress::class);
    }

    /**
     * Die Verifizierungspflicht greift vor der Zahlung (Masterprompt 8.1).
     * Die Pruefbestaetigung selbst gibt es nur noch in Schritt 10 ueber die
     * Vorschau; der fruehere Weg ueber die Detailseite ist entfernt.
     */
    public function test_verifizierungspflicht_blockiert_die_zahlung(): void
    {
        $welt = $this->welt(false);
        $lauf = $this->lauf($welt['organization'], $welt['user']);

        $antwort = $this->actingAs($welt['user'])->post(
            route('portal.checkout.store', ['billingRun' => $lauf->getKey()]),
            ['sofortige_ausfuehrung' => '1', 'vertragsgrundlagen' => '1']
        );

        $antwort->assertForbidden();
        self::assertNull($lauf->fresh()?->getAttribute('paid_at'));
    }

    public function test_signierter_final_download_verlangt_eine_bestaetigte_adresse(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $welt = $this->welt(false);
        $lauf = $this->lauf($welt['organization'], $welt['user']);

        Storage::disk('local')->put('abrechnungen/final/test.pdf', '%PDF-1.4 Test');

        /** @var GeneratedDocument $artefakt */
        $artefakt = GeneratedDocument::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'kind' => GeneratedDocumentKind::MIETERABRECHNUNG,
            'variant' => GeneratedDocumentVariant::FINAL,
            'status' => GeneratedDocumentStatus::AKTIV,
            'storage_disk' => 'local',
            'storage_path' => 'abrechnungen/final/test.pdf',
        ]);

        $url = app(SignedDownloadUrlFactory::class)->forRoute(
            'portal.downloads.signed',
            ['generatedDocument' => (string) $artefakt->getKey()]
        );

        // Dieselbe Pflicht wie auf der Streaming-Route (Masterprompt 8.1): der
        // Link aus der Abschlussmail umgeht die Verifizierung nicht.
        $this->actingAs($welt['user'])->get($url)->assertForbidden();

        $welt['user']->forceFill(['email_verified_at' => now()])->save();

        self::assertNotSame(403, $this->actingAs($welt['user']->refresh())->get($url)->getStatusCode());
    }

    public function test_die_pruefbestaetigung_gibt_es_nur_ueber_die_vorschau(): void
    {
        $welt = $this->welt(true);
        $lauf = $this->lauf($welt['organization'], $welt['user']);

        self::assertFalse(Route::has('portal.abrechnungen.bestaetigen'));

        $antwort = $this->actingAs($welt['user'])->post(
            '/app/abrechnungen/'.$lauf->getKey().'/bestaetigen',
            ['werte_geprueft' => '1', 'verantwortung_uebernommen' => '1']
        );

        self::assertContains($antwort->getStatusCode(), [404, 405]);
        self::assertNull($lauf->fresh()?->getAttribute('review_confirmed_at'));

        // Ohne gueltige Vorschau ist auch der eine Weg gesperrt.
        $this->actingAs($welt['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $lauf->getKey()]),
            ['bestaetigung' => '1']
        )->assertSessionHasErrors('bestaetigung');

        self::assertNull($lauf->fresh()?->getAttribute('review_confirmed_at'));
    }

    public function test_bestaetigungsmail_enthaelt_html_und_klartext(): void
    {
        $welt = $this->welt(false);
        $verifikation = app(EmailVerification::class);

        $nachricht = (new VerifyEmailAddress($verifikation->signedUrl($welt['user'])))
            ->toMail($welt['user']);

        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $nachricht->subject);
        self::assertSame(
            ['emails.auth.verifizierung', 'emails.auth.verifizierung-text'],
            $nachricht->view
        );

        $gerendert = $nachricht->render();
        self::assertStringContainsString('Bitte bestätigen Sie Ihre E-Mail-Adresse', (string) $gerendert);
    }
}
