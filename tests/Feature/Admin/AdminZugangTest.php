<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\AdminRoleAssignment;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Zugang zum internen Bereich (Masterprompt 19, 20; ARCHITECTURE.md T10).
 *
 * Adminrechte werden ausschliesslich aus der getrennten Tabelle admin_roles
 * abgeleitet, niemals aus einer Kundenrolle.
 */
final class AdminZugangTest extends AdminTestCase
{
    public function test_ein_gast_wird_zur_anmeldung_geleitet(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_ein_angemeldeter_kunde_erhaelt_keinen_zugang(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($kunde['user'])->get('/admin')->assertNotFound();
    }

    public function test_eine_kundenrolle_verschafft_keine_adminrechte(): void
    {
        $kunde = $this->kunde();

        // Der Nutzer ist OWNER seiner Organisation, also Inhaber der hoechsten
        // Kundenrolle. Adminrechte entstehen daraus ausdruecklich nicht.
        self::assertFalse($kunde['user']->isStaff());

        $this->actingAs($kunde['user'])->get('/admin/livegang')->assertNotFound();
    }

    #[DataProvider('routenProvider')]
    public function test_jede_lesende_adminroute_ist_gegen_einen_normalen_nutzer_abgesichert(string $route): void
    {
        $kunde = $this->kunde();

        $this->actingAs($kunde['user'])->get($route)->assertNotFound();
    }

    #[DataProvider('routenProvider')]
    public function test_jede_lesende_adminroute_ist_fuer_eine_interne_kennung_erreichbar(string $route): void
    {
        $this->actingAs($this->interneKennung())->get($route)->assertOk();
    }

    /**
     * @return list<array{string}>
     */
    public static function routenProvider(): array
    {
        return array_map(
            static fn (string $route): array => [$route],
            self::lesendeRouten(),
        );
    }

    public function test_eine_entzogene_adminrolle_verschafft_keinen_zugang(): void
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create(['two_factor_confirmed_at' => now()]);

        AdminRoleAssignment::query()->create([
            'user_id' => $nutzer->getKey(),
            'role' => AdminRole::ADMIN,
            'granted_at' => now()->subDay(),
            'revoked_at' => now(),
        ]);

        $this->actingAs($nutzer)->get('/admin')->assertNotFound();
    }

    public function test_eine_supportrolle_erhaelt_zugang(): void
    {
        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))->get('/admin')->assertOk();
    }

    public function test_schreibende_handlungen_sind_fuer_einen_kunden_gesperrt(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($kunde['user'])
            ->post('/admin/datenschutz/loeschungen/wiederholen')
            ->assertNotFound();

        $this->actingAs($kunde['user'])
            ->post('/admin/preise/pruefen', ['preis_brutto_cent' => 2490])
            ->assertNotFound();
    }

    public function test_der_adminbereich_verwendet_ein_eigenes_layout_ohne_kundenmenue(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin');

        $antwort->assertOk();
        $antwort->assertSee('Interner Bereich');
        $antwort->assertDontSee('Ihre Betriebskostenabrechnung');
        $antwort->assertDontSee(route('portal.dashboard'));
    }
}
