<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\AccountDeletionWorkflow;
use App\Application\Privacy\CreateDataExport;
use App\Application\Privacy\Dto\AccountDeletionReport;
use App\Application\Privacy\ExecuteAccountDeletion;
use App\Enums\GeneratedDocumentKind;
use App\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Endgueltige Loeschung nach Ablauf der Frist (Masterprompt 19).
 *
 * Geprueft werden: die Ausfuehrung selbst, die Entkopplung der HVM-Rechnungen,
 * die Nichtauffindbarkeit der Kundendaten in mehreren Tabellen, die
 * Protokolleintraege, die Idempotenz und die Wiederaufnahme.
 */
final class AccountDeletionExecutionTest extends PrivacyTestCase
{
    public function test_endgueltige_loeschung_nach_ablauf_der_frist(): void
    {
        $a = $this->mandant('A');
        $this->beantrageUndWarte($a);

        $bericht = $this->fuehreAus($a['user']);

        self::assertTrue($bericht->executed);
        self::assertSame(1, $bericht->deletedOrganizations);

        $this->assertDatabaseMissing('users', ['id' => $a['user']->getKey()]);
        $this->assertDatabaseMissing('organizations', ['id' => $a['organization']->getKey()]);
    }

    public function test_ohne_abgelaufene_frist_wird_nicht_geloescht(): void
    {
        $a = $this->mandant('A');

        $bericht = $this->fuehreAus($a['user']);

        self::assertFalse($bericht->executed);
        self::assertTrue($bericht->alreadyDeleted);
        $this->assertDatabaseHas('users', ['id' => $a['user']->getKey()]);
    }

    public function test_keine_kundendaten_mehr_auffindbar(): void
    {
        $a = $this->mandant('A');

        /** @var ReminderPreference $erinnerung */
        $erinnerung = ReminderPreference::factory()->create([
            'organization_id' => $a['organization']->getKey(),
            'user_id' => $a['user']->getKey(),
            'property_id' => $a['property']->getKey(),
        ]);

        /** @var EmailMessage $nachricht */
        $nachricht = EmailMessage::factory()->create([
            'organization_id' => $a['organization']->getKey(),
            'user_id' => $a['user']->getKey(),
            'billing_run_id' => $a['billingRun']->getKey(),
            'recipient_email' => (string) $a['user']->getAttribute('email'),
        ]);

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        $tabellen = [
            'users' => ['id' => $a['user']->getKey()],
            'organizations' => ['id' => $a['organization']->getKey()],
            'organization_user' => ['user_id' => $a['user']->getKey()],
            'properties' => ['id' => $a['property']->getKey()],
            'units' => ['id' => $a['unit']->getKey()],
            'tenancies' => ['id' => $a['tenancy']->getKey()],
            'billing_runs' => ['id' => $a['billingRun']->getKey()],
            'documents' => ['id' => $a['document']->getKey()],
            'extracted_fields' => ['id' => $a['field']->getKey()],
            'reminder_preferences' => ['id' => $erinnerung->getKey()],
            'email_messages' => ['id' => $nachricht->getKey()],
        ];

        foreach ($tabellen as $tabelle => $bedingung) {
            $this->assertDatabaseMissing($tabelle, $bedingung);
        }
    }

    public function test_erzeugte_abrechnungs_pdfs_werden_mit_datei_geloescht(): void
    {
        $a = $this->mandant('A');
        $abrechnungspfad = (string) $a['statementPdf']->getAttribute('storage_path');

        self::assertTrue(Storage::disk('local')->exists($abrechnungspfad));

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        self::assertFalse(Storage::disk('local')->exists($abrechnungspfad));
        $this->assertDatabaseMissing('generated_documents', ['id' => $a['statementPdf']->getKey()]);
    }

    public function test_datenexporte_werden_mit_geloescht(): void
    {
        $a = $this->mandant('A');

        /** @var CreateDataExport $export */
        $export = app(CreateDataExport::class);
        $ergebnis = $export($a['user'], $a['organization']);
        $exportpfad = (string) $ergebnis->document->getAttribute('storage_path');

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        self::assertFalse(Storage::disk('local')->exists($exportpfad));
        $this->assertDatabaseMissing('generated_documents', ['id' => $ergebnis->document->getKey()]);
    }

    public function test_hvm_rechnung_bleibt_erhalten_und_ist_entkoppelt(): void
    {
        $a = $this->mandant('A');
        $nummer = (string) $a['invoice']->getAttribute('number');
        $rechnungspfad = (string) $a['invoicePdf']->getAttribute('storage_path');

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        /** @var Invoice|null $rechnung */
        $rechnung = Invoice::query()->where('number', $nummer)->first();

        self::assertInstanceOf(Invoice::class, $rechnung);
        self::assertNull($rechnung->getAttribute('organization_id'));
        self::assertNull($rechnung->getAttribute('user_id'));
        self::assertNull($rechnung->getAttribute('billing_run_id'));
        self::assertNull($rechnung->getAttribute('payment_id'));

        // Die aufbewahrungspflichtigen Angaben bleiben unveraendert.
        self::assertSame('Rechnungsempfänger A', $rechnung->getAttribute('customer_name'));
        self::assertNotNull($rechnung->getAttribute('issued_on'));
        self::assertNotNull($rechnung->getAttribute('gross_cent'));

        /** @var GeneratedDocument|null $beleg */
        $beleg = GeneratedDocument::query()->whereKey($a['invoicePdf']->getKey())->first();

        self::assertInstanceOf(GeneratedDocument::class, $beleg);
        self::assertNull($beleg->getAttribute('organization_id'));
        self::assertNull($beleg->getAttribute('billing_run_id'));
        self::assertSame(GeneratedDocumentKind::HVM_RECHNUNG, $beleg->getAttribute('kind'));
        self::assertTrue(Storage::disk('local')->exists($rechnungspfad));
    }

    public function test_audit_eintraege_fuer_antrag_ruecknahme_und_loeschung(): void
    {
        $a = $this->mandant('A');
        $userId = (string) $a['user']->getKey();

        $workflow = $this->workflow();

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $workflow->request($a['user'], $a['organization']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00'));
        $workflow->withdraw($a['user'], $a['organization']);
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        $workflow->request($a['user'], $a['organization']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));

        $this->fuehreAus($a['user']);
        Carbon::setTestNow();

        self::assertSame(2, AuditLog::query()
            ->where('action', AccountDeletionWorkflow::ACTION_REQUESTED)->count());
        self::assertSame(1, AuditLog::query()
            ->where('action', AccountDeletionWorkflow::ACTION_WITHDRAWN)->count());

        /** @var AuditLog|null $ausfuehrung */
        $ausfuehrung = AuditLog::query()
            ->where('action', AccountDeletionWorkflow::ACTION_EXECUTED)
            ->first();

        self::assertInstanceOf(AuditLog::class, $ausfuehrung);

        $metadata = $ausfuehrung->getAttribute('metadata');
        self::assertIsArray($metadata);
        self::assertSame($userId, $metadata['user_reference'] ?? null);

        // Nach der Loeschung verweist kein Protokolleintrag mehr auf Nutzer
        // oder Mandant, die Nachweise selbst bleiben erhalten.
        self::assertSame(0, AuditLog::query()->where('actor_user_id', $userId)->count());
        self::assertSame(
            0,
            AuditLog::query()->where('organization_id', $a['organization']->getKey())->count()
        );
    }

    public function test_protokoll_enthaelt_nach_der_loeschung_keine_kontaktdaten(): void
    {
        $a = $this->mandant('A');
        $email = (string) $a['user']->getAttribute('email');

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        foreach (AuditLog::query()->get() as $eintrag) {
            $roh = json_encode($eintrag->attributesToArray());
            self::assertIsString($roh);
            self::assertStringNotContainsString($email, $roh);
        }
    }

    public function test_loeschung_ist_idempotent(): void
    {
        $a = $this->mandant('A');
        $this->beantrageUndWarte($a);

        $erster = $this->fuehreAus($a['user']);
        self::assertTrue($erster->executed);

        // Der Nutzer existiert nicht mehr, der Lauf findet nichts mehr.
        self::assertSame([], $this->workflow()->due());

        $zweiter = $this->kommando();
        self::assertSame(0, $zweiter);
    }

    public function test_command_fuehrt_faellige_loeschungen_aus(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $this->beantrageUndWarte($a);

        self::assertSame(0, $this->kommando());

        $this->assertDatabaseMissing('users', ['id' => $a['user']->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $b['user']->getKey()]);
        $this->assertDatabaseHas('organizations', ['id' => $b['organization']->getKey()]);
    }

    public function test_command_ohne_faellige_antraege_meldet_dies(): void
    {
        $this->mandant('A');

        $this->artisan('smartabrechnen:execute-account-deletions')
            ->expectsOutputToContain('Keine fälligen Kontolöschungen.')
            ->assertExitCode(0);
    }

    public function test_geteilter_mandant_wird_nicht_geloescht(): void
    {
        $a = $this->mandant('A');

        /** @var User $zweiter */
        $zweiter = User::factory()->create(['email' => 'zweiter@example.test']);

        OrganizationUser::query()->create([
            'organization_id' => $a['organization']->getKey(),
            'user_id' => $zweiter->getKey(),
            'role' => OrganizationRole::MEMBER,
            'joined_at' => now(),
        ]);

        $this->beantrageUndWarte($a);
        $bericht = $this->fuehreAus($a['user']);

        self::assertTrue($bericht->executed);
        self::assertSame(0, $bericht->deletedOrganizations);

        $this->assertDatabaseMissing('users', ['id' => $a['user']->getKey()]);
        $this->assertDatabaseHas('organizations', ['id' => $a['organization']->getKey()]);
        $this->assertDatabaseHas('properties', ['id' => $a['property']->getKey()]);
        $this->assertDatabaseMissing('organization_user', ['user_id' => $a['user']->getKey()]);
    }

    public function test_fremder_mandant_bleibt_vollstaendig_unberuehrt(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        $this->assertDatabaseHas('users', ['id' => $b['user']->getKey()]);
        $this->assertDatabaseHas('properties', ['id' => $b['property']->getKey()]);
        $this->assertDatabaseHas('extracted_fields', ['id' => $b['field']->getKey()]);
        $this->assertDatabaseHas('generated_documents', ['id' => $b['statementPdf']->getKey()]);

        self::assertTrue(Storage::disk('local')->exists(
            (string) $b['statementPdf']->getAttribute('storage_path')
        ));
        self::assertSame(
            $b['organization']->getKey(),
            Invoice::query()->whereKey($b['invoice']->getKey())->value('organization_id')
        );
    }

    public function test_sitzungen_und_kennwortmarken_werden_entfernt(): void
    {
        $a = $this->mandant('A');
        $email = (string) $a['user']->getAttribute('email');

        DB::table('sessions')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $a['user']->getKey(),
            'payload' => 'leer',
            'last_activity' => time(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => 'testmarke',
            'created_at' => now(),
        ]);

        $this->beantrageUndWarte($a);
        $this->fuehreAus($a['user']);

        $this->assertDatabaseMissing('sessions', ['user_id' => $a['user']->getKey()]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }

    /**
     * @param  array<string, mixed>  $mandant
     */
    private function beantrageUndWarte(array $mandant): void
    {
        $nutzer = $mandant['user'];
        $organisation = $mandant['organization'];

        self::assertInstanceOf(User::class, $nutzer);
        self::assertInstanceOf(Organization::class, $organisation);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($nutzer, $organisation);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));
    }

    private function fuehreAus(User $nutzer): AccountDeletionReport
    {
        /** @var ExecuteAccountDeletion $use */
        $use = app(ExecuteAccountDeletion::class);

        $bericht = $use($nutzer);

        Carbon::setTestNow();

        return $bericht;
    }

    private function kommando(): int
    {
        $code = $this->artisan('smartabrechnen:execute-account-deletions')->run();

        Carbon::setTestNow();

        return $code;
    }

    private function workflow(): AccountDeletionWorkflow
    {
        /** @var AccountDeletionWorkflow $workflow */
        $workflow = app(AccountDeletionWorkflow::class);

        return $workflow;
    }
}
