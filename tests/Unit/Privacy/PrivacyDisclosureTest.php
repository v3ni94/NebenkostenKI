<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Application\Privacy\Dto\AccountDeletionState;
use App\Application\Privacy\PrivacyDisclosure;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Auskunftstexte und Fristberechnung (Masterprompt 6.4 und 19).
 */
final class PrivacyDisclosureTest extends TestCase
{
    public function test_auskunft_nennt_originaldateien_als_nicht_dauerhaft(): void
    {
        $text = implode(' ', PrivacyDisclosure::neverStoredPermanently());

        self::assertStringContainsString('Originaldateien', $text);
        self::assertStringContainsString('OCR-Texte', $text);
        self::assertStringContainsString('Seitenbilder', $text);
        self::assertStringContainsString('EXIF', $text);
    }

    public function test_auskunft_nennt_die_dauerhaft_gespeicherten_daten(): void
    {
        $text = implode(' ', PrivacyDisclosure::storedPermanently());

        self::assertStringContainsString('Fundstellenausschnitt', $text);
        self::assertStringContainsString('Fingerabdruck', $text);
        self::assertStringContainsString('Berechnungsstände', $text);
    }

    public function test_auskunft_trennt_geloeschte_und_erhaltene_daten(): void
    {
        $geloescht = implode(' ', PrivacyDisclosure::deletedOnAccountDeletion());
        $erhalten = implode(' ', PrivacyDisclosure::retainedOnAccountDeletion());

        self::assertStringContainsString('Abrechnungs-PDFs', $geloescht);
        self::assertStringContainsString('entkoppelt', $erhalten);
        self::assertStringContainsString('Hausverwaltung Müller GmbH', $erhalten);
    }

    public function test_hinweis_auf_die_eigene_aufbewahrung_ist_eindeutig(): void
    {
        $text = PrivacyDisclosure::ownRecordsNotice();

        self::assertStringContainsString('Bitte bewahren Sie Ihre Originalrechnungen', $text);
        self::assertStringContainsString('keine Möglichkeit', $text);
    }

    public function test_verfahrenshinweis_nennt_die_frist(): void
    {
        self::assertStringContainsString('45 Tagen', PrivacyDisclosure::deletionProcessNotice(45));
    }

    public function test_texte_enthalten_keine_gedankenstriche(): void
    {
        $alle = array_merge(
            PrivacyDisclosure::storedPermanently(),
            PrivacyDisclosure::neverStoredPermanently(),
            PrivacyDisclosure::deletedOnAccountDeletion(),
            PrivacyDisclosure::retainedOnAccountDeletion(),
            [PrivacyDisclosure::ownRecordsNotice(), PrivacyDisclosure::deletionProcessNotice(30)],
        );

        foreach ($alle as $text) {
            self::assertStringNotContainsString('–', $text);
            self::assertStringNotContainsString('—', $text);
        }
    }

    public function test_zustand_ohne_antrag_ist_nicht_faellig(): void
    {
        $zustand = AccountDeletionState::none(30);

        self::assertFalse($zustand->pending);
        self::assertFalse($zustand->isDue());
        self::assertSame(0, $zustand->remainingDays());
        self::assertSame('', $zustand->dueAtLabel());
        self::assertSame('', $zustand->requestedAtLabel());
    }

    public function test_zustand_mit_antrag_rechnet_die_restfrist(): void
    {
        $beantragt = Carbon::parse('2026-09-01 10:00:00');
        $faellig = Carbon::parse('2026-10-01 10:00:00');

        $zustand = AccountDeletionState::pending($beantragt, $faellig, 30);

        self::assertTrue($zustand->pending);
        self::assertSame('01.10.2026', $zustand->dueAtLabel());
        self::assertSame('01.09.2026', $zustand->requestedAtLabel());
        self::assertSame(10, $zustand->remainingDays(Carbon::parse('2026-09-21 10:00:00')));
        self::assertFalse($zustand->isDue(Carbon::parse('2026-09-30 10:00:00')));
        self::assertTrue($zustand->isDue(Carbon::parse('2026-10-01 10:00:00')));
        self::assertSame(0, $zustand->remainingDays(Carbon::parse('2026-10-02 10:00:00')));
    }
}
