<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use Tests\TestCase;

final class RedirectToCanonicalHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://beispiel.test');
    }

    public function test_www_wird_dauerhaft_auf_die_kanonische_domain_umgeleitet(): void
    {
        $antwort = $this->get('http://www.beispiel.test/preise?tab=2&x=y');

        $antwort->assertStatus(301);
        $antwort->assertRedirect('https://beispiel.test/preise?tab=2&x=y');
    }

    public function test_grossschreibung_im_host_wird_ebenfalls_umgeleitet(): void
    {
        $this->get('https://WWW.Beispiel.test/')->assertRedirect('https://beispiel.test/');
    }

    public function test_kanonische_domain_wird_nicht_umgeleitet(): void
    {
        $this->get('https://beispiel.test/preise')->assertOk();
    }

    public function test_andere_hostnamen_bleiben_unberuehrt(): void
    {
        $this->get('http://staging.beispiel.test/preise')->assertOk();
        $this->get('http://localhost/preise')->assertOk();
    }

    public function test_schreibende_anfrage_an_www_wird_nicht_still_umgeleitet(): void
    {
        $this->post('http://www.beispiel.test/anmelden', [])->assertStatus(403);
    }

    public function test_ohne_app_url_keine_umleitung(): void
    {
        config()->set('app.url', '');

        $this->get('http://www.beispiel.test/preise')->assertOk();
    }

    public function test_app_url_mit_www_kennt_keine_umleitung(): void
    {
        config()->set('app.url', 'https://www.beispiel.test');

        $this->get('http://www.beispiel.test/preise')->assertOk();
        $this->get('http://beispiel.test/preise')->assertOk();
    }
}
