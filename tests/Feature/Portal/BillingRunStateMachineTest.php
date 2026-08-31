<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Application\BillingRun\RecordCorrection;
use App\Enums\BillingRunStatus;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\BillingRunVersion;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Statusmaschine des Abrechnungslaufs.
 *
 * Verbindliche Regeln: erlaubte Uebergaenge stehen ausschliesslich in der
 * Uebergangstabelle, nach PAID gibt es keinen Rueckweg in die Bearbeitung,
 * FINALIZED und CANCELLED sind endgueltig, jeder Uebergang schreibt einen
 * Revisionseintrag.
 */
final class BillingRunStateMachineTest extends PortalTestCase
{
    /**
     * @param  array<string, mixed>  $abweichungen
     */
    private function lauf(BillingRunStatus $status, array $abweichungen = []): BillingRun
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create(array_merge([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
            'status' => $status,
        ], $abweichungen));

        return $lauf;
    }

    /**
     * Erlaubte Uebergaenge.
     *
     * @return array<string, array{0: BillingRunStatus, 1: BillingRunStatus}>
     */
    public static function erlaubteUebergaenge(): array
    {
        return [
            'Entwurf zu Upload' => [BillingRunStatus::DRAFT, BillingRunStatus::UPLOADING],
            'Upload zu Auswertung' => [BillingRunStatus::UPLOADING, BillingRunStatus::EXTRACTING],
            'Auswertung zu Pruefung' => [BillingRunStatus::EXTRACTING, BillingRunStatus::REVIEW_REQUIRED],
            'Pruefung zu bereit' => [BillingRunStatus::REVIEW_REQUIRED, BillingRunStatus::READY_FOR_CALCULATION],
            'bereit zu berechnet' => [BillingRunStatus::READY_FOR_CALCULATION, BillingRunStatus::CALCULATED],
            'berechnet zu Vorschau' => [BillingRunStatus::CALCULATED, BillingRunStatus::PREVIEW_READY],
            'Vorschau zu Checkout' => [BillingRunStatus::PREVIEW_READY, BillingRunStatus::CHECKOUT_PENDING],
            'Checkout zurueck zur Vorschau' => [BillingRunStatus::CHECKOUT_PENDING, BillingRunStatus::PREVIEW_READY],
            'Checkout zu bezahlt' => [BillingRunStatus::CHECKOUT_PENDING, BillingRunStatus::PAID],
            'Vorschau zurueck zur Pruefung' => [BillingRunStatus::PREVIEW_READY, BillingRunStatus::REVIEW_REQUIRED],
            'Entwurf zu abgebrochen' => [BillingRunStatus::DRAFT, BillingRunStatus::CANCELLED],
            'Vorschau zu fehlgeschlagen' => [BillingRunStatus::PREVIEW_READY, BillingRunStatus::FAILED],
        ];
    }

    /**
     * Verbotene Uebergaenge.
     *
     * @return array<string, array{0: BillingRunStatus, 1: BillingRunStatus}>
     */
    public static function verboteneUebergaenge(): array
    {
        return [
            'Entwurf direkt zu bezahlt' => [BillingRunStatus::DRAFT, BillingRunStatus::PAID],
            'Entwurf direkt zu abgeschlossen' => [BillingRunStatus::DRAFT, BillingRunStatus::FINALIZED],
            'Vorschau direkt zu bezahlt' => [BillingRunStatus::PREVIEW_READY, BillingRunStatus::PAID],
            'bezahlt zurueck zur Vorschau' => [BillingRunStatus::PAID, BillingRunStatus::PREVIEW_READY],
            'bezahlt zurueck zur Pruefung' => [BillingRunStatus::PAID, BillingRunStatus::REVIEW_REQUIRED],
            'bezahlt zurueck zum Entwurf' => [BillingRunStatus::PAID, BillingRunStatus::DRAFT],
            'bezahlt zu abgebrochen' => [BillingRunStatus::PAID, BillingRunStatus::CANCELLED],
            'bezahlt direkt zu abgeschlossen' => [BillingRunStatus::PAID, BillingRunStatus::FINALIZED],
            'in Erstellung zurueck zur Vorschau' => [BillingRunStatus::FINALIZING, BillingRunStatus::PREVIEW_READY],
            'abgeschlossen zu abgebrochen' => [BillingRunStatus::FINALIZED, BillingRunStatus::CANCELLED],
            'abgeschlossen zurueck zur Berechnung' => [BillingRunStatus::FINALIZED, BillingRunStatus::CALCULATED],
            'abgebrochen zurueck zum Entwurf' => [BillingRunStatus::CANCELLED, BillingRunStatus::DRAFT],
            'Auswertung zu Checkout' => [BillingRunStatus::EXTRACTING, BillingRunStatus::CHECKOUT_PENDING],
        ];
    }

    #[DataProvider('erlaubteUebergaenge')]
    public function test_erlaubter_uebergang_wird_ausgefuehrt(BillingRunStatus $von, BillingRunStatus $nach): void
    {
        $lauf = $this->lauf($von, $von === BillingRunStatus::PAID ? ['paid_at' => now()] : []);
        $maschine = app(BillingRunStateMachine::class);

        $maschine->transitionTo($lauf, $nach);

        self::assertSame($nach, BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status'));
    }

    #[DataProvider('verboteneUebergaenge')]
    public function test_verbotener_uebergang_wirft_eine_ausnahme(BillingRunStatus $von, BillingRunStatus $nach): void
    {
        $lauf = $this->lauf($von, $von->isPaid() ? ['paid_at' => now()] : []);
        $maschine = app(BillingRunStateMachine::class);

        $this->expectException(IllegalStatusTransitionException::class);

        try {
            $maschine->transitionTo($lauf, $nach);
        } finally {
            // Der Status bleibt unveraendert.
            self::assertSame($von, BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status'));
        }
    }

    public function test_nach_bezahlt_ist_kein_bearbeitungsstatus_erreichbar(): void
    {
        foreach ([BillingRunStatus::PAID, BillingRunStatus::FINALIZING, BillingRunStatus::FINALIZED] as $bezahlt) {
            foreach (BillingRunStatus::cases() as $ziel) {
                if (! $ziel->isEditable()) {
                    continue;
                }

                self::assertFalse(
                    BillingRunStateMachine::isAllowed($bezahlt, $ziel),
                    'Von '.$bezahlt->value.' darf nicht nach '.$ziel->value.' gewechselt werden.'
                );
            }
        }
    }

    public function test_finalisierter_lauf_ist_endgueltig(): void
    {
        self::assertSame([], BillingRunStateMachine::allowedTargets(BillingRunStatus::FINALIZED));
        self::assertSame([], BillingRunStateMachine::allowedTargets(BillingRunStatus::CANCELLED));
    }

    public function test_finalisierung_setzt_eine_bestaetigte_zahlung_voraus(): void
    {
        // Status PAID ohne paid_at ist ein widerspruechlicher Zustand.
        $lauf = $this->lauf(BillingRunStatus::PAID, ['paid_at' => null]);
        $maschine = app(BillingRunStateMachine::class);

        $this->expectException(IllegalStatusTransitionException::class);
        $this->expectExceptionMessage('bestätigte Zahlung');

        $maschine->transitionTo($lauf, BillingRunStatus::FINALIZING);
    }

    public function test_erneuter_versuch_nach_fehler_nur_mit_bestaetigter_zahlung(): void
    {
        $maschine = app(BillingRunStateMachine::class);

        $ohneZahlung = $this->lauf(BillingRunStatus::FAILED, ['paid_at' => null]);
        self::assertFalse($maschine->canTransition($ohneZahlung, BillingRunStatus::FINALIZING));

        $mitZahlung = $this->lauf(BillingRunStatus::FAILED, ['paid_at' => now()]);
        self::assertTrue($maschine->canTransition($mitZahlung, BillingRunStatus::FINALIZING));
    }

    public function test_uebergang_setzt_die_fachlichen_zeitstempel(): void
    {
        $maschine = app(BillingRunStateMachine::class);

        $checkout = $this->lauf(BillingRunStatus::CHECKOUT_PENDING);
        $maschine->transitionTo($checkout, BillingRunStatus::PAID);
        self::assertNotNull(BillingRun::query()->findOrFail($checkout->getKey())->getAttribute('paid_at'));

        $erstellung = $this->lauf(BillingRunStatus::FINALIZING, ['paid_at' => now()]);
        $maschine->transitionTo($erstellung, BillingRunStatus::FINALIZED);
        self::assertNotNull(BillingRun::query()->findOrFail($erstellung->getKey())->getAttribute('finalized_at'));

        $abbruch = $this->lauf(BillingRunStatus::DRAFT);
        $maschine->transitionTo($abbruch, BillingRunStatus::CANCELLED);
        self::assertNotNull(BillingRun::query()->findOrFail($abbruch->getKey())->getAttribute('cancelled_at'));
    }

    public function test_jeder_uebergang_schreibt_einen_revisionseintrag(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::DRAFT,
        ]);

        app(BillingRunStateMachine::class)->transitionTo(
            billingRun: $lauf,
            to: BillingRunStatus::UPLOADING,
            actor: $mandant['user'],
            reason: 'Nachweis',
        );

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()
            ->where('action', BillingRunStateMachine::AUDIT_ACTION)
            ->firstOrFail();

        self::assertSame($mandant['user']->getKey(), $eintrag->getAttribute('actor_user_id'));
        self::assertSame(BillingRun::class, $eintrag->getAttribute('subject_type'));
        self::assertSame($lauf->getKey(), $eintrag->getAttribute('subject_id'));
        self::assertSame($mandant['organization']->getKey(), $eintrag->getAttribute('organization_id'));
        self::assertNotNull($eintrag->getAttribute('occurred_at'));
        self::assertSame('127.0.0.0', $eintrag->getAttribute('ip_truncated'));
        self::assertSame('Nachweis', $eintrag->getAttribute('reason'));

        $metadaten = $eintrag->getAttribute('metadata');
        self::assertIsArray($metadaten);
        self::assertSame('DRAFT', $metadaten['von']);
        self::assertSame('UPLOADING', $metadaten['nach']);
    }

    public function test_verbotener_uebergang_schreibt_keinen_revisionseintrag(): void
    {
        $lauf = $this->lauf(BillingRunStatus::FINALIZED);

        try {
            app(BillingRunStateMachine::class)->transitionTo($lauf, BillingRunStatus::DRAFT);
        } catch (IllegalStatusTransitionException) {
            // erwartet
        }

        self::assertSame(
            0,
            AuditLog::query()->where('action', BillingRunStateMachine::AUDIT_ACTION)->count()
        );
    }

    public function test_gleicher_status_ist_kein_gueltiger_uebergang(): void
    {
        $lauf = $this->lauf(BillingRunStatus::DRAFT);

        $this->expectException(IllegalStatusTransitionException::class);

        app(BillingRunStateMachine::class)->transitionTo($lauf, BillingRunStatus::DRAFT);
    }

    public function test_korrektur_erzeugt_eine_neue_version_und_laesst_die_alte_bestehen(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::FINALIZED,
            'paid_at' => now(),
            'finalized_at' => now(),
        ]);

        $korrektur = app(RecordCorrection::class);

        $erste = $korrektur->handle($lauf, ['betrag_cent' => 1000], $mandant['user'], 'Erstfassung');
        $zweite = $korrektur->handle($lauf, ['betrag_cent' => 1200], $mandant['user'], 'Korrektur');

        self::assertSame(1, $erste->getAttribute('version_number'));
        self::assertSame(2, $zweite->getAttribute('version_number'));
        self::assertSame(2, BillingRunVersion::query()->where('billing_run_id', $lauf->getKey())->count());

        // Die erste Fassung ist unveraendert.
        /** @var BillingRunVersion $unveraendert */
        $unveraendert = BillingRunVersion::query()->findOrFail($erste->getKey());
        self::assertSame(['betrag_cent' => 1000], $unveraendert->getAttribute('payload'));
        self::assertNotSame(
            $unveraendert->getAttribute('payload_hash'),
            $zweite->getAttribute('payload_hash')
        );

        // Der finalisierte Lauf bleibt finalisiert.
        self::assertSame(
            BillingRunStatus::FINALIZED,
            BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status')
        );

        self::assertTrue(
            AuditLog::query()->where('action', RecordCorrection::AUDIT_ACTION)->exists()
        );
    }

    public function test_abbruch_ueber_die_route_nutzt_die_statusmaschine(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::DRAFT,
        ]);

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.abrechnungen.abbrechen', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertRedirect(route('portal.abrechnungen.index'));
        self::assertSame(
            BillingRunStatus::CANCELLED,
            BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status')
        );
    }

    public function test_abbruch_eines_bezahlten_laufs_wird_verstaendlich_abgelehnt(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->paid()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
        ]);

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]))
            ->post(route('portal.abrechnungen.abbrechen', ['billingRun' => $lauf->getKey()]));

        // Die BillingRunPolicy verweigert das Loeschen eines bezahlten Laufs
        // bereits vor der Statusmaschine.
        self::assertContains($antwort->getStatusCode(), [302, 403]);
        self::assertSame(
            BillingRunStatus::PAID,
            BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status')
        );
    }
}
