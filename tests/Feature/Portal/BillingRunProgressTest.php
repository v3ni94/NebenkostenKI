<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Enums\BillingRunStatus;
use App\Models\AuditLog;
use App\Models\BillingRun;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fortschritt des Abrechnungslaufs im echten Ablauf.
 *
 * Nachgewiesen wird: jeder fachliche Schritt schaltet genau einen Zustand
 * weiter, ein zweiter Aufruf ist wirkungslos, es gibt keinen Rueckschritt und
 * ein bezahlter, finalisierter oder abgebrochener Lauf bleibt unberuehrt.
 */
final class BillingRunProgressTest extends PortalTestCase
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

    private function fortschritt(): BillingRunProgress
    {
        return app(BillingRunProgress::class);
    }

    private function laufStatus(BillingRun $lauf): BillingRunStatus
    {
        $status = BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status');

        self::assertInstanceOf(BillingRunStatus::class, $status);

        return $status;
    }

    private function anzahlStatuswechsel(BillingRun $lauf): int
    {
        return AuditLog::query()
            ->where('action', BillingRunStateMachine::AUDIT_ACTION)
            ->where('subject_id', $lauf->getKey())
            ->count();
    }

    /**
     * Jeder Schritt an seinem fachlichen Ausgangspunkt.
     *
     * @return array<string, array{0: BillingRunStatus, 1: string, 2: BillingRunStatus}>
     */
    public static function schritte(): array
    {
        return [
            'Upload begonnen' => [
                BillingRunStatus::DRAFT, 'uploadBegonnen', BillingRunStatus::UPLOADING,
            ],
            'Extraktion begonnen' => [
                BillingRunStatus::UPLOADING, 'extraktionBegonnen', BillingRunStatus::EXTRACTING,
            ],
            'Pruefung erforderlich' => [
                BillingRunStatus::EXTRACTING, 'pruefungErforderlich', BillingRunStatus::REVIEW_REQUIRED,
            ],
            'bereit zur Berechnung' => [
                BillingRunStatus::REVIEW_REQUIRED, 'bereitZurBerechnung', BillingRunStatus::READY_FOR_CALCULATION,
            ],
            'berechnet' => [
                BillingRunStatus::READY_FOR_CALCULATION, 'berechnet', BillingRunStatus::CALCULATED,
            ],
            'Vorschau bereit' => [
                BillingRunStatus::CALCULATED, 'vorschauBereit', BillingRunStatus::PREVIEW_READY,
            ],
        ];
    }

    #[DataProvider('schritte')]
    public function test_jeder_schritt_schaltet_den_erwarteten_zustand(
        BillingRunStatus $von,
        string $methode,
        BillingRunStatus $nach,
    ): void {
        $lauf = $this->lauf($von);

        $this->fortschritt()->{$methode}($lauf);

        self::assertSame($nach, $this->laufStatus($lauf));
        self::assertSame(1, $this->anzahlStatuswechsel($lauf));
    }

    #[DataProvider('schritte')]
    public function test_ein_zweiter_aufruf_ist_wirkungslos(
        BillingRunStatus $von,
        string $methode,
        BillingRunStatus $nach,
    ): void {
        $lauf = $this->lauf($von);

        $this->fortschritt()->{$methode}($lauf);
        $this->fortschritt()->{$methode}($lauf);
        $this->fortschritt()->{$methode}($lauf);

        self::assertSame($nach, $this->laufStatus($lauf));
        self::assertSame(1, $this->anzahlStatuswechsel($lauf));
    }

    public function test_der_vollstaendige_weg_entsteht_ohne_zustand_zu_ueberspringen(): void
    {
        $lauf = $this->lauf(BillingRunStatus::DRAFT);
        $fortschritt = $this->fortschritt();

        $fortschritt->uploadBegonnen($lauf);
        $fortschritt->extraktionBegonnen($lauf);
        $fortschritt->pruefungErforderlich($lauf);
        $fortschritt->bereitZurBerechnung($lauf);
        $fortschritt->berechnet($lauf);
        $fortschritt->vorschauBereit($lauf);

        self::assertSame(BillingRunStatus::PREVIEW_READY, $this->laufStatus($lauf));

        $folge = AuditLog::query()
            ->where('action', BillingRunStateMachine::AUDIT_ACTION)
            ->where('subject_id', $lauf->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(static function (AuditLog $eintrag): string {
                $metadaten = $eintrag->getAttribute('metadata');

                return is_array($metadaten) && is_string($metadaten['nach'] ?? null) ? $metadaten['nach'] : '';
            })
            ->all();

        self::assertSame([
            BillingRunStatus::UPLOADING->value,
            BillingRunStatus::EXTRACTING->value,
            BillingRunStatus::REVIEW_REQUIRED->value,
            BillingRunStatus::READY_FOR_CALCULATION->value,
            BillingRunStatus::CALCULATED->value,
            BillingRunStatus::PREVIEW_READY->value,
        ], $folge);
    }

    public function test_ein_uebersprungener_zwischenschritt_wird_nachgeholt(): void
    {
        $lauf = $this->lauf(BillingRunStatus::DRAFT);

        // Die Vorschau wird verlangt, obwohl der Lauf noch im Entwurf steht.
        // Der Fortschritt laeuft die Kette entlang, statt einen Zustand zu
        // ueberspringen.
        $this->fortschritt()->vorschauBereit($lauf);

        self::assertSame(BillingRunStatus::PREVIEW_READY, $this->laufStatus($lauf));
        self::assertSame(6, $this->anzahlStatuswechsel($lauf));
    }

    /**
     * Zustaende, aus denen kein Fortschritt mehr stattfindet.
     *
     * @return array<string, array{0: BillingRunStatus, 1: array<string, mixed>}>
     */
    public static function unberuehrbareZustaende(): array
    {
        return [
            'bezahlt' => [BillingRunStatus::PAID, ['paid_at' => '2026-01-01 10:00:00']],
            'in Erstellung' => [BillingRunStatus::FINALIZING, ['paid_at' => '2026-01-01 10:00:00']],
            'abgeschlossen' => [BillingRunStatus::FINALIZED, ['paid_at' => '2026-01-01 10:00:00']],
            'abgebrochen' => [BillingRunStatus::CANCELLED, []],
            'fehlgeschlagen' => [BillingRunStatus::FAILED, []],
        ];
    }

    /**
     * @param  array<string, mixed>  $abweichungen
     */
    #[DataProvider('unberuehrbareZustaende')]
    public function test_ein_bezahlter_oder_abgeschlossener_lauf_faellt_nicht_zurueck(
        BillingRunStatus $status,
        array $abweichungen,
    ): void {
        $lauf = $this->lauf($status, $abweichungen);
        $fortschritt = $this->fortschritt();

        // Jeder Wizard-Schritt wird erneut aufgerufen, wie es ein zweiter
        // Browser-Tab tun wuerde.
        $fortschritt->uploadBegonnen($lauf);
        $fortschritt->extraktionBegonnen($lauf);
        $fortschritt->pruefungErforderlich($lauf);
        $fortschritt->bereitZurBerechnung($lauf);
        $fortschritt->berechnet($lauf);
        $fortschritt->vorschauBereit($lauf);

        self::assertSame($status, $this->laufStatus($lauf));
        self::assertSame(0, $this->anzahlStatuswechsel($lauf));
    }

    public function test_ein_abgebrochener_lauf_bleibt_abgebrochen(): void
    {
        $lauf = $this->lauf(BillingRunStatus::CANCELLED);

        $this->fortschritt()->uploadBegonnen($lauf);

        self::assertSame(BillingRunStatus::CANCELLED, $this->laufStatus($lauf));
        self::assertSame(0, $this->anzahlStatuswechsel($lauf));
    }

    public function test_ein_eingeleiteter_checkout_wird_nicht_zurueckgeschaltet(): void
    {
        $lauf = $this->lauf(BillingRunStatus::CHECKOUT_PENDING);
        $fortschritt = $this->fortschritt();

        $fortschritt->berechnet($lauf);
        $fortschritt->vorschauBereit($lauf);
        $fortschritt->pruefungErforderlich($lauf);

        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, $this->laufStatus($lauf));
        self::assertSame(0, $this->anzahlStatuswechsel($lauf));
    }

    public function test_ein_frueherer_schritt_schaltet_einen_weiter_fortgeschrittenen_lauf_nicht_zurueck(): void
    {
        $lauf = $this->lauf(BillingRunStatus::PREVIEW_READY);
        $fortschritt = $this->fortschritt();

        $fortschritt->uploadBegonnen($lauf);
        $fortschritt->extraktionBegonnen($lauf);
        $fortschritt->pruefungErforderlich($lauf);
        $fortschritt->bereitZurBerechnung($lauf);
        $fortschritt->berechnet($lauf);

        self::assertSame(BillingRunStatus::PREVIEW_READY, $this->laufStatus($lauf));
        self::assertSame(0, $this->anzahlStatuswechsel($lauf));
    }

    public function test_der_fortschritt_wirft_keine_ausnahme_bei_wirkungslosem_aufruf(): void
    {
        $lauf = $this->lauf(BillingRunStatus::FINALIZED, ['paid_at' => now(), 'finalized_at' => now()]);

        $ergebnis = $this->fortschritt()->uploadBegonnen($lauf);

        self::assertSame($lauf->getKey(), $ergebnis->getKey());
        self::assertSame(BillingRunStatus::FINALIZED, $this->laufStatus($lauf));
    }

    public function test_der_fortschritt_sagt_vorab_ob_er_weiterschaltet(): void
    {
        $entwurf = $this->lauf(BillingRunStatus::DRAFT);
        $bezahlt = $this->lauf(BillingRunStatus::PAID, ['paid_at' => now()]);

        self::assertTrue($this->fortschritt()->wuerdeWeiterschalten($entwurf, BillingRunStatus::UPLOADING));
        self::assertFalse($this->fortschritt()->wuerdeWeiterschalten($bezahlt, BillingRunStatus::UPLOADING));
    }

    public function test_der_statuswechsel_haelt_den_fachlichen_schritt_im_revisionseintrag_fest(): void
    {
        $lauf = $this->lauf(BillingRunStatus::CALCULATED);

        $this->fortschritt()->vorschauBereit($lauf);

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()
            ->where('action', BillingRunStateMachine::AUDIT_ACTION)
            ->where('subject_id', $lauf->getKey())
            ->firstOrFail();

        $metadaten = $eintrag->getAttribute('metadata');

        self::assertIsArray($metadaten);
        self::assertSame(BillingRunStatus::CALCULATED->value, $metadaten['von']);
        self::assertSame(BillingRunStatus::PREVIEW_READY->value, $metadaten['nach']);
        self::assertSame(BillingRunStatus::PREVIEW_READY->value, $metadaten['fortschritt']);
    }
}
