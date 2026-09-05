<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Featuretests des oeffentlichen Frontends und der Rechtstext-Platzhalterseiten.
 *
 * Die Seiten sind statische Blade-Views ohne Datenbankzugriff. Deshalb wird
 * bewusst kein RefreshDatabase verwendet.
 */
final class SitePagesTest extends TestCase
{
    /**
     * Alle oeffentlichen Seiten mit einem charakteristischen Inhalt.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function seiten(): array
    {
        return [
            'Startseite' => ['site.home', 'entsteht aus den Unterlagen, die Sie bereits haben'],
            'Ablauf' => ['site.ablauf', 'So entsteht Ihre Betriebskosten'],
            'Preise' => ['site.preise', 'Ein Festpreis je erzeugter Mieter'],
            'Datenschutz und Loeschung' => ['site.datenschutz-konzept', 'Ihre Originaldateien werden nach der Auswertung gelöscht'],
            'Haeufige Fragen' => ['site.faq', 'Warum werden meine Dateien gelöscht?'],
            'Kontakt' => ['site.kontakt', 'kontakt@smart-abrechnen.de'],
            'Impressum' => ['legal.impressum', 'Impressum'],
            // Die Titel der Rechtstexte tragen einen weichen Trennstrich (&shy;); geprueft wird ein Teilsatz ohne Trennstelle.
            'Datenschutzerklaerung' => ['legal.datenschutz', 'Gliederung der Datenschutzerklärung'],
            'AGB' => ['legal.agb', 'Allgemeine Geschäfts'],
            'Widerrufsbelehrung' => ['legal.widerruf', 'Widerrufs'],
        ];
    }

    /**
     * Die vier Rechtstextseiten.
     *
     * @return array<string, array{0: string}>
     */
    public static function rechtstextseiten(): array
    {
        return [
            'Impressum' => ['legal.impressum'],
            'Datenschutzerklaerung' => ['legal.datenschutz'],
            'AGB' => ['legal.agb'],
            'Widerrufsbelehrung' => ['legal.widerruf'],
        ];
    }

    #[DataProvider('seiten')]
    public function test_seite_ist_erreichbar_und_zeigt_ihren_inhalt(string $routenname, string $inhalt): void
    {
        $antwort = $this->get(route($routenname));

        $antwort->assertOk();
        $antwort->assertSee($inhalt);
    }

    #[DataProvider('seiten')]
    public function test_seite_liefert_grundgeruest_und_metaangaben(string $routenname, string $inhalt): void
    {
        $antwort = $this->get(route($routenname));

        $antwort->assertOk();
        $antwort->assertSee('<html lang="de">', false);
        $antwort->assertSee('name="description"', false);
        $antwort->assertSee('Zum Hauptinhalt springen');
        $antwort->assertSee('id="hauptinhalt"', false);
        $antwort->assertSee($inhalt);
    }

    #[DataProvider('seiten')]
    public function test_seite_verlinkt_alle_rechtstexte(string $routenname, string $inhalt): void
    {
        $antwort = $this->get(route($routenname));

        $antwort->assertOk();
        $antwort->assertSee($inhalt);
        $antwort->assertSee(route('legal.impressum'));
        $antwort->assertSee(route('legal.datenschutz'));
        $antwort->assertSee(route('legal.agb'));
        $antwort->assertSee(route('legal.widerruf'));
    }

    #[DataProvider('rechtstextseiten')]
    public function test_rechtstextseite_zeigt_den_freigabehinweis(string $routenname): void
    {
        $antwort = $this->get(route($routenname));

        $antwort->assertOk();
        $antwort->assertSee('VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN');
        $antwort->assertSee('anwaltlich');
    }

    public function test_startseite_zeigt_claim_und_beide_abrechnungswege(): void
    {
        $antwort = $this->get(route('site.home'));

        $antwort->assertOk();
        $antwort->assertSee('Die digitalste Hausverwaltung');
        $antwort->assertSee('Schnellabrechnung für die Eigentumswohnung');
        $antwort->assertSee('Vollständige Objektabrechnung für Mehrfamilienhäuser');
        $antwort->assertSee('Unterlagen hochladen');
        $antwort->assertSee('Nach Zahlung die Final-PDFs erhalten');
        $antwort->assertSee('Was Sie brauchen');
        $antwort->assertSee('Bitte bewahren Sie Ihre Originalbelege selbst auf.');
        $antwort->assertSee('Keine Rechtsberatung');
    }

    /**
     * Befund N13: Die Website verspricht keine Uebernahme von Werten aus
     * Mietvertrag, Vorjahresabrechnung oder Zahlungsuebersicht, solange diese
     * Ueberfuehrung nicht umgesetzt ist (ARCHITECTURE 11.1).
     */
    public function test_website_verspricht_keine_uebernahme_aus_mietvertrag_und_zahlungsuebersicht(): void
    {
        $start = $this->get(route('site.home'));

        $start->assertOk();
        $start->assertDontSee('aus Mietvertrag, Vorjahr oder Zahlungsübersicht', false);
        $start->assertDontSee('Vieles davon liest das Portal aus Ihren', false);
        $start->assertSee('die Werte daraus werden nicht automatisch', false);
        $start->assertSee('tragen Sie aus dem Vertrag in die geführten Schritte ein', false);

        $faq = $this->get(route('site.faq'));

        $faq->assertOk();
        $faq->assertDontSee('Tabellen für Mieter-, Zahlungs- und', false);
        $faq->assertSee('werden derzeit nicht automatisch', false);
        $faq->assertSee('XLSX', false);

        $ablauf = $this->get(route('site.ablauf'));

        $ablauf->assertOk();
        $ablauf->assertSee('im Vorjahreslauf bestätigte Schlüssel', false);
        $ablauf->assertDontSee('Regelung aus dem Mietvertrag', false);
    }

    /**
     * Befund R14: Die Startseite beschreibt nur den umgesetzten Umfang. Ein
     * Abgleich mit Vorjahreswerten findet nicht statt (ARCHITECTURE 11.1),
     * und Personentage sind bei Leerstand gesperrt.
     */
    public function test_startseite_verspricht_keinen_vorjahresabgleich_und_benennt_die_leerstandsgrenze(): void
    {
        $start = $this->get(route('site.home'));

        $start->assertOk();
        $start->assertDontSee('Vorjahreswerten und Prüfsummen', false);
        $start->assertSee('den Abgleich von Belegsummen und Prüfsummen', false);
        $start->assertSee('Personentage ist deshalb bei Leerstand nicht verwendbar', false);
    }

    public function test_startseite_zeigt_die_betreiberangaben_unveraendert(): void
    {
        $antwort = $this->get(route('site.home'));
        $betreiber = config('smartabrechnen.operator');

        $antwort->assertOk();
        $antwort->assertSee('Hausverwaltung Müller GmbH');
        $antwort->assertSee('Rheinpromenade 13');
        $antwort->assertSee('40789 Monheim am Rhein');
        $antwort->assertSee('Amtsgericht Düsseldorf, HRB 104762');
        $antwort->assertSee('Geschäftsführer: Timo Müller');

        // Die Werte muessen exakt der Konfiguration entsprechen.
        $antwort->assertSee($betreiber['legal_name']);
        $antwort->assertSee($betreiber['address_line']);
        $antwort->assertSee($betreiber['register_number']);
        $antwort->assertSee($betreiber['managing_director']);

        // Keine ASCII-Umschrift der Firmierung im sichtbaren Text.
        $antwort->assertDontSee('Hausverwaltung Mueller GmbH');
    }

    public function test_startseite_ordnet_die_marke_der_betreiberin_zu_und_zeigt_ihr_logo(): void
    {
        $antwort = $this->get(route('site.home'));

        $antwort->assertOk();
        $antwort->assertSee('Smart Abrechnen ist ein Dienst der Hausverwaltung Müller GmbH.');
        $antwort->assertSee('Ein Dienst der Hausverwaltung Müller GmbH');
        $antwort->assertDontSee('Angebot der');
        $antwort->assertSee('ci/Logo_HVM.jpg');
        $antwort->assertSee('alt="Hausverwaltung Müller GmbH"', false);
    }

    public function test_startseite_gibt_den_preis_aus_der_konfiguration_aus(): void
    {
        $antwort = $this->get(route('site.home'));

        $bruttoCent = (int) config('smartabrechnen.pricing.per_statement_gross_cent');
        $erwartet = number_format($bruttoCent / 100, 2, ',', '.').' EUR';

        $antwort->assertOk();
        $antwort->assertSee($erwartet);
        $antwort->assertSee('Konto und Entwürfe kostenlos');
        $antwort->assertSee('Zahlung erst nach Prüfung der Vorschau');
        $antwort->assertSee('Kein Abonnement und keine Grundgebühr');
    }

    public function test_startseite_verweist_auf_die_anwendung(): void
    {
        $antwort = $this->get(route('site.home'));

        $antwort->assertOk();
        $antwort->assertSee('Kostenlos starten');
        $antwort->assertSee('Anmelden');
        $antwort->assertSee(url('/app'));
    }

    public function test_ablaufseite_zeigt_alle_zwoelf_schritte(): void
    {
        $antwort = $this->get(route('site.ablauf'));

        $antwort->assertOk();

        foreach (range(1, 12) as $nummer) {
            $antwort->assertSee('Schritt '.$nummer.': ');
        }

        $antwort->assertSee('Konto und Abrechnungsjahr');
        $antwort->assertSee('Automatische Analyse');
        $antwort->assertSee('Verteilerschlüssel und Verbrauch');
        $antwort->assertSee('Prüfbericht');
        $antwort->assertSee('Vorschau mit Wasserzeichen');
        $antwort->assertSee('Finalisierung');
    }

    public function test_preisseite_weist_netto_und_umsatzsteuer_getrennt_aus(): void
    {
        $antwort = $this->get(route('site.preise'));

        $bruttoCent = (int) config('smartabrechnen.pricing.per_statement_gross_cent');
        $grundpreisCent = (int) config('smartabrechnen.pricing.base_gross_cent');
        $satz = (int) config('smartabrechnen.pricing.vat_rate_percent');

        $nettoCent = (int) round($bruttoCent / (1 + $satz / 100));
        $steuerCent = $bruttoCent - $nettoCent;

        $beispielBruttoCent = 7 * $bruttoCent + $grundpreisCent;
        $beispielNettoCent = (int) round($beispielBruttoCent / (1 + $satz / 100));
        $beispielSteuerCent = $beispielBruttoCent - $beispielNettoCent;

        $euro = static fn (int $cent): string => number_format($cent / 100, 2, ',', '.').' EUR';

        $antwort->assertOk();
        $antwort->assertSee($euro($bruttoCent));
        $antwort->assertSee($euro($nettoCent));
        $antwort->assertSee($euro($steuerCent));
        $antwort->assertSee($euro($beispielBruttoCent));
        $antwort->assertSee($euro($beispielNettoCent));
        $antwort->assertSee($euro($beispielSteuerCent));
        $antwort->assertSee('Umsatzsteuer '.$satz.' Prozent');
        $antwort->assertSee('7 Stück');
        $antwort->assertSee('Kein Abonnement');
    }

    public function test_datenschutzseite_erklaert_das_loeschkonzept_ohne_garantieversprechen(): void
    {
        $antwort = $this->get(route('site.datenschutz-konzept'));

        $ttl = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');

        $antwort->assertOk();
        $antwort->assertSee('verschlüsselten temporären Bereich');
        $antwort->assertSee('höchstens '.$ttl.' Minuten');
        $antwort->assertSee('Ausschluss aus Backups');
        $antwort->assertSee('Nicht dauerhaft gespeichert');
        $antwort->assertSee('Bitte bewahren Sie Ihre Originalbelege selbst auf');

        // Es darf keine forensische Loeschung behauptet werden.
        $antwort->assertSee('Es wird nicht behauptet, dass Dateien auf');
        $antwort->assertDontSee('rechtssicher');
        $antwort->assertDontSee('garantiert gelöscht');
    }

    public function test_faqseite_enthaelt_mindestens_zwoelf_fragen(): void
    {
        $antwort = $this->get(route('site.faq'));

        $antwort->assertOk();

        $inhalt = $antwort->getContent();
        self::assertIsString($inhalt);
        self::assertGreaterThanOrEqual(12, substr_count($inhalt, 'aria-controls="faq-antwort-'));

        $antwort->assertSee('Was passiert, wenn eine Angabe fehlt?');
        $antwort->assertSee('Wie werden Mieterwechsel und Leerstand behandelt?');
        $antwort->assertSee('Was ist mit den Heizkosten?');
        $antwort->assertSee('Warum sind Verwaltungs- und Reparaturkosten nicht umlagefähig?');
        $antwort->assertSee('Wann zahle ich?');
        $antwort->assertSee('Was kostet die Nutzung?');
        $antwort->assertSee('Kann ich Daten für Folgejahre übernehmen?');
        $antwort->assertSee('Was sehen meine Mieter?');
        $antwort->assertSee('Erhalte ich eine Rechnung?');
        $antwort->assertSee('Wie kann ich mein Konto löschen?');
        $antwort->assertSee('Erhalte ich Erinnerungen?');
    }

    public function test_kontaktseite_nennt_die_mailadresse_und_keine_telefonnummer(): void
    {
        $antwort = $this->get(route('site.kontakt'));

        $antwort->assertOk();
        $antwort->assertSee('kontakt@smart-abrechnen.de');
        $antwort->assertSee('Hausverwaltung Müller GmbH');
        $antwort->assertSee('Keine Unterlagen per E-Mail');
        $antwort->assertDontSee('Telefon');
    }

    public function test_impressum_gibt_die_pflichtangaben_aus_der_konfiguration_aus(): void
    {
        $antwort = $this->get(route('legal.impressum'));

        $antwort->assertOk();
        $antwort->assertSee('Hausverwaltung Müller GmbH');
        $antwort->assertSee('Rheinpromenade 13');
        $antwort->assertSee('40789 Monheim am Rhein');
        $antwort->assertSee('Amtsgericht Düsseldorf');
        $antwort->assertSee('HRB 104762');
        $antwort->assertSee('Timo Müller');
        $antwort->assertSee('https://www.muellerhv.de/');
    }

    public function test_impressum_zeigt_platzhalter_fuer_fehlende_steuerangaben(): void
    {
        $antwort = $this->get(route('legal.impressum'));
        $betreiber = config('smartabrechnen.operator');

        $antwort->assertOk();

        if (blank($betreiber['vat_id']) || blank($betreiber['tax_id'])) {
            $antwort->assertSee($betreiber['placeholder_text']);
            $antwort->assertSee('Angabe fehlt noch');
        } else {
            $antwort->assertSee($betreiber['vat_id']);
            $antwort->assertSee($betreiber['tax_id']);
        }
    }

    #[DataProvider('rechtstextseiten')]
    public function test_rechtstextseite_enthaelt_nur_platzhalterinhalte(string $routenname): void
    {
        $antwort = $this->get(route($routenname));

        $antwort->assertOk();
        $antwort->assertSee('Platzhalterfassung');
        $antwort->assertSee('[');
    }
}
