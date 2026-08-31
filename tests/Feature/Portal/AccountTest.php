<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrganizationType;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Kontobereich: Stammdaten, E-Mail-Aenderung und Erinnerungen.
 */
final class AccountTest extends PortalTestCase
{
    public function test_kontoseite_zeigt_die_eigenen_angaben(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.konto.edit'));

        $antwort->assertOk();
        $antwort->assertSee('Ihr Konto');
        $antwort->assertSee((string) $mandant['user']->getAttribute('name'));
        $antwort->assertSee('Rechnungsanschrift');
        $antwort->assertSee('Erinnerungen');
        $antwort->assertSee('Zwei-Faktor-Authentifizierung');
        $antwort->assertSee('In Vorbereitung');
    }

    public function test_stammdaten_und_rechnungsanschrift_werden_gespeichert(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->put(route('portal.konto.update'), [
            'name' => 'Timo Beispiel',
            'organization_name' => 'Beispiel Immobilien',
            'organization_type' => 'UNTERNEHMEN',
            'billing_name' => 'Beispiel Immobilien GmbH',
            'billing_address_line' => 'Musterweg 7',
            'billing_postal_code' => '40789',
            'billing_city' => 'Monheim am Rhein',
            'vat_id' => 'DE123456789',
        ]);

        $antwort->assertRedirect(route('portal.konto.edit'));

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($mandant['user']->getKey());
        /** @var Organization $organisation */
        $organisation = Organization::query()->findOrFail($mandant['organization']->getKey());

        self::assertSame('Timo Beispiel', $nutzer->getAttribute('name'));
        self::assertSame('Beispiel Immobilien', $organisation->getAttribute('name'));
        self::assertSame(OrganizationType::UNTERNEHMEN, $organisation->getAttribute('type'));
        self::assertSame('Musterweg 7', $organisation->getAttribute('billing_address_line'));
        self::assertSame('DE123456789', $organisation->getAttribute('vat_id'));

        self::assertTrue(AuditLog::query()->where('action', 'account.updated')->exists());
    }

    public function test_stammdaten_ohne_namen_werden_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.konto.edit'))
            ->put(route('portal.konto.update'), [
                'name' => '',
                'organization_name' => '',
                'organization_type' => 'PRIVATPERSON',
            ]);

        $antwort->assertSessionHasErrors(['name', 'organization_name']);
    }

    public function test_emailaenderung_erfordert_das_aktuelle_passwort(): void
    {
        $mandant = $this->mandant();
        $mandant['user']->forceFill(['password' => Hash::make('richtiges-passwort-2026')])->save();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.konto.edit'))
            ->put(route('portal.konto.email'), [
                'email' => 'neu@beispiel.invalid',
                'current_password' => 'falsches-passwort-2026',
            ]);

        $antwort->assertSessionHasErrors('current_password');
        self::assertNotSame(
            'neu@beispiel.invalid',
            User::query()->findOrFail($mandant['user']->getKey())->getAttribute('email')
        );
    }

    public function test_emailaenderung_setzt_die_verifizierung_zurueck_und_versendet_einen_link(): void
    {
        Notification::fake();

        $mandant = $this->mandant();
        $mandant['user']->forceFill(['password' => Hash::make('richtiges-passwort-2026')])->save();

        $antwort = $this->actingAs($mandant['user'])->put(route('portal.konto.email'), [
            'email' => 'neu@beispiel.invalid',
            'current_password' => 'richtiges-passwort-2026',
        ]);

        $antwort->assertRedirect(route('portal.konto.edit'));

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($mandant['user']->getKey());

        self::assertSame('neu@beispiel.invalid', $nutzer->getAttribute('email'));
        self::assertNull($nutzer->getAttribute('email_verified_at'));
        self::assertSame(UserStatus::UNBESTAETIGT, $nutzer->getAttribute('status'));

        Notification::assertSentTo($nutzer, VerifyEmailAddress::class);
        self::assertTrue(AuditLog::query()->where('action', 'account.email_changed')->exists());
    }

    public function test_bereits_vergebene_adresse_wird_neutral_abgelehnt(): void
    {
        $mandant = $this->mandant();
        $mandant['user']->forceFill(['password' => Hash::make('richtiges-passwort-2026')])->save();

        User::factory()->create(['email' => 'belegt@beispiel.invalid']);

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.konto.edit'))
            ->put(route('portal.konto.email'), [
                'email' => 'belegt@beispiel.invalid',
                'current_password' => 'richtiges-passwort-2026',
            ]);

        $antwort->assertSessionHasErrors('email');
        self::assertStringContainsString(
            'kann nicht verwendet werden',
            (string) session('errors')?->first('email')
        );
    }

    public function test_erinnerungen_lassen_sich_global_abschalten(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->put(route('portal.konto.erinnerungen'), [
            'q1_enabled' => '1',
        ]);

        $antwort->assertRedirect(route('portal.konto.edit'));

        /** @var ReminderPreference $global */
        $global = ReminderPreference::query()
            ->where('user_id', $mandant['user']->getKey())
            ->whereNull('property_id')
            ->firstOrFail();

        self::assertFalse((bool) $global->getAttribute('is_active'));
        self::assertTrue((bool) $global->getAttribute('q1_enabled'));
        self::assertFalse((bool) $global->getAttribute('december_enabled'));
        self::assertNotNull($global->getAttribute('deactivated_at'));
    }

    public function test_erinnerungen_lassen_sich_je_objekt_steuern(): void
    {
        $mandant = $this->mandant();
        $objektId = (string) $mandant['property']->getKey();

        $this->actingAs($mandant['user'])->put(route('portal.konto.erinnerungen'), [
            'global_active' => '1',
            'q1_enabled' => '1',
            'q2_enabled' => '1',
            'q3_enabled' => '1',
            'december_enabled' => '1',
            'objekte' => [$objektId => '0'],
        ]);

        /** @var ReminderPreference $jeObjekt */
        $jeObjekt = ReminderPreference::query()
            ->where('user_id', $mandant['user']->getKey())
            ->where('property_id', $objektId)
            ->firstOrFail();

        self::assertFalse((bool) $jeObjekt->getAttribute('is_active'));
        self::assertSame(64, strlen((string) $jeObjekt->getAttribute('unsubscribe_token')));

        // Danach wieder einschalten.
        $this->actingAs($mandant['user'])->put(route('portal.konto.erinnerungen'), [
            'global_active' => '1',
            'objekte' => [$objektId => '1'],
        ]);

        self::assertTrue(
            (bool) ReminderPreference::query()
                ->where('user_id', $mandant['user']->getKey())
                ->where('property_id', $objektId)
                ->firstOrFail()
                ->getAttribute('is_active')
        );

        self::assertTrue(AuditLog::query()->where('action', 'account.reminders_updated')->exists());
    }

    public function test_unbestaetigte_adresse_wird_im_konto_deutlich_angezeigt(): void
    {
        $mandant = $this->mandant();
        $mandant['user']->forceFill(['email_verified_at' => null])->save();

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.konto.edit'));

        $antwort->assertOk();
        $antwort->assertSee('E-Mail-Adresse noch nicht bestätigt');
        $antwort->assertSee('Zahlung und Download der fertigen Abrechnungen sind erst nach der Bestätigung möglich.');
    }

    public function test_portallayout_zeigt_keine_marketingnavigation(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Angemeldet als');
        $antwort->assertSee('Abmelden');
        $antwort->assertSee('Übersicht');
        $antwort->assertDontSee('Die digitalste Hausverwaltung');
        $antwort->assertDontSee('Kostenlos starten');
        $antwort->assertDontSee(route('site.preise'));
    }

    public function test_deutsche_texte_enthalten_keine_gedankenstriche(): void
    {
        $mandant = $this->mandant();

        foreach ([
            route('portal.dashboard'),
            route('portal.objekte.index'),
            route('portal.objekte.create'),
            route('portal.abrechnungen.create'),
            route('portal.konto.edit'),
        ] as $url) {
            $antwort = $this->actingAs($mandant['user'])->get($url);
            $antwort->assertOk();

            $inhalt = (string) $antwort->getContent();
            $sichtbar = trim(strip_tags($inhalt));

            self::assertFalse(
                Str::contains($sichtbar, [' – ', ' — ']),
                'Die Seite '.$url.' enthält einen Gedankenstrich im deutschen Text.'
            );
        }
    }
}
