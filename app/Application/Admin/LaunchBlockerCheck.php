<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Payment\OperatorInvoiceBlocker;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\ProviderReleaseGate;
use App\Services\Storage\MalwareScannerFactory;
use Throwable;

/**
 * Rechtliche und technische Livegang-Blocker (Masterprompt 2.1, 6.3, 13.5,
 * 20, 26; ARCHITECTURE.md Abschnitt 11).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Diese Klasse LIEST nur. Sie setzt keine Konfiguration, erfindet keine
 *     Betreiberangabe und behauptet keine Freigabe. Ein Punkt gilt genau dann
 *     als erfuellt, wenn die zugrunde liegende Quelle ihn ausdruecklich als
 *     erfuellt meldet.
 *  2. Die Freigabepruefung des KI-Providers wird bewusst gegen die Umgebung
 *     "production" gerechnet, auch wenn der Adminbereich lokal geoeffnet wird.
 *     Der Bereich soll den Zustand zum Livegang zeigen, nicht den bequemen
 *     Zustand der Entwicklungsumgebung.
 *  3. Es wird kein Secret ausgegeben, auch nicht teilweise maskiert. Zu
 *     Schluesseln wird ausschliesslich gemeldet, ob sie gesetzt sind.
 *
 * QUELLEN
 *
 *  - App\Application\Payment\OperatorInvoiceBlocker  Steuer- und Bankdaten
 *  - App\Services\Storage\MalwareScannerFactory      Malwaretreiber
 *  - App\Services\Ai\ProviderReleaseGate             Datenschutzfreigabe
 *  - config('services.stripe')                       Zahlungsanbindung
 *  - resources/views/legal                           Platzhalterstatus
 *  - public/ci                                       Logo und CI-Dateien
 *  - config('smartabrechnen.retention')              Aufbewahrungsfristen
 *  - config('smartabrechnen.pricing')                Korrekturfrist
 */
final class LaunchBlockerCheck
{
    public const string BETREIBERDATEN = 'betreiberdaten';

    public const string STRIPE = 'stripe';

    public const string KI_DATENSCHUTZFREIGABE = 'ki_datenschutzfreigabe';

    public const string MALWARE_SCANNER = 'malware_scanner';

    public const string RECHTSTEXTE = 'rechtstexte';

    public const string CI_ASSETS = 'ci_assets';

    public const string AUFBEWAHRUNG_EXTRAKTIONSDATEN = 'aufbewahrung_extraktionsdaten';

    public const string AUFBEWAHRUNG_ERGEBNIS_PDF = 'aufbewahrung_ergebnis_pdf';

    public const string KORREKTURFRIST = 'korrekturfrist';

    /**
     * Marker, an dem eine Rechtstextseite als Platzhalterfassung erkennbar ist.
     *
     * Jede Platzhalterseite bezeichnet sich in ihrem eigenen Text ausdruecklich
     * als Platzhalterfassung, zusaetzlich traegt das Layout der Rechtstexte den
     * sichtbaren Warnhinweis (Komponente hvm.legal-placeholder-banner). Mit der
     * anwaltlich freigegebenen Textfassung entfaellt beides. Geprueft wird der
     * Seitentext, weil er je Seite ersetzt wird.
     */
    private const string PLATZHALTER_MARKER = 'Platzhalter';

    /**
     * Erwartete CI-Dateien nach public/ci/README.md. Es wird kein Logo erzeugt.
     *
     * @var list<string>
     */
    private const array CI_DATEIEN = ['Logo_HVM.svg', 'Logo_HVM.jpg'];

    /**
     * @var list<string>
     */
    private const array RECHTSTEXTSEITEN = [
        'impressum.blade.php',
        'datenschutz.blade.php',
        'agb.blade.php',
        'widerruf.blade.php',
    ];

    private readonly string $legalViewPath;

    private readonly string $ciAssetPath;

    public function __construct(
        private readonly OperatorInvoiceBlocker $operator,
        ?string $legalViewPath = null,
        ?string $ciAssetPath = null,
    ) {
        $this->legalViewPath = $legalViewPath ?? resource_path('views/legal');
        $this->ciAssetPath = $ciAssetPath ?? public_path('ci');
    }

    public function report(): LaunchBlockerReport
    {
        $blockers = [];

        foreach ([
            $this->operatorMasterdata(),
            $this->stripe(),
            $this->aiRelease(),
            $this->malwareScanner(),
            $this->legalTexts(),
            $this->corporateIdentity(),
            $this->extractedDataRetention(),
            $this->generatedPdfRetention(),
            $this->correctionPeriod(),
        ] as $blocker) {
            if ($blocker instanceof LaunchBlocker) {
                $blockers[] = $blocker;
            }
        }

        return new LaunchBlockerReport($blockers);
    }

    // -----------------------------------------------------------------
    // Einzelpruefungen
    // -----------------------------------------------------------------

    private function operatorMasterdata(): ?LaunchBlocker
    {
        $state = $this->operator->state();

        if ($state['blockiert'] === false) {
            return null;
        }

        $missing = $state['fehlende_angaben'] === []
            ? 'Die Betreiberangaben sind vollständig, aber nicht ausdrücklich bestätigt.'
            : 'Es fehlen bestätigte Betreiberangaben: '.implode(', ', $state['fehlende_angaben']).'.';

        return new LaunchBlocker(
            self::BETREIBERDATEN,
            'Betreiber und Rechnung',
            $missing,
            'Die produktive Rechnungserzeugung ist blockiert. Eine Rechnung würde den sichtbaren Platzhalter '
                .'tragen und wäre nicht ordnungsgemäß.',
            'Geschäftsführung der Hausverwaltung Müller GmbH, in Abstimmung mit dem Steuerberater.',
        );
    }

    private function stripe(): ?LaunchBlocker
    {
        $missing = [];

        foreach (['key' => 'Veröffentlichbarer Schlüssel', 'secret' => 'Geheimer Schlüssel', 'webhook_secret' => 'Webhook-Secret'] as $key => $label) {
            if ($this->configString('services.stripe.'.$key) === null) {
                $missing[] = $label;
            }
        }

        if ($missing === []) {
            return null;
        }

        return new LaunchBlocker(
            self::STRIPE,
            'Zahlung',
            'Die Zahlungsanbindung ist unvollständig konfiguriert. Nicht gesetzt: '.implode(', ', $missing).'.',
            'Es kann keine Zahlung entgegengenommen und keine Finalisierung ausgelöst werden. Ohne Webhook-Secret '
                .'wäre eine Benachrichtigung nicht prüfbar und würde verworfen.',
            'Betreiber über das Stripe-Konto. Die Schlüssel gehören ausschließlich in die Serverumgebung.',
        );
    }

    private function aiRelease(): ?LaunchBlocker
    {
        try {
            $config = AiConfig::fromArray($this->configArray('ai'));
        } catch (Throwable) {
            return new LaunchBlocker(
                self::KI_DATENSCHUTZFREIGABE,
                'KI-Provider',
                'Die KI-Konfiguration ist unvollständig oder fehlerhaft.',
                'Die Dokumentverarbeitung ist produktiv nicht einsatzfähig.',
                'Betreiber, gemeinsam mit der technischen Betreuung.',
            );
        }

        // Bewertung ausdruecklich aus Sicht der Produktion, siehe Klassenkopf.
        $gate = new ProviderReleaseGate(
            $config->requireZeroDataRetention,
            $config->dataRetentionApproved,
            'production',
        );

        $keys = [$config->primaryProvider];

        if ($config->fallbackEnabled && $config->fallbackProvider !== null) {
            $keys[] = $config->fallbackProvider;
        }

        $reasons = [];

        foreach (array_unique($keys, SORT_REGULAR) as $key) {
            if ($key === AiProviderKey::FAKE) {
                $reasons[] = 'Als Provider ist der Testprovider eingestellt. Er liefert keine fachlich '
                    .'belastbaren Ergebnisse und ist produktiv gesperrt.';

                continue;
            }

            $reason = $gate->blockReason($key);

            if ($reason !== null) {
                $reasons[] = sprintf('%s: %s', $key->value, $reason);
            }
        }

        if ($reasons === []) {
            return null;
        }

        return new LaunchBlocker(
            self::KI_DATENSCHUTZFREIGABE,
            'KI-Provider',
            implode(' ', $reasons),
            'Der Provider ist produktiv blockiert. Es wird kein Dokument an einen nicht freigegebenen Provider '
                .'übertragen. Die Dokumentverarbeitung steht damit still.',
            'Betreiber: Auftragsverarbeitungsvertrag, aktuelle Retention-Dokumentation und ausdrückliche '
                .'Datenschutzentscheidung je Providerorganisation, Modell und genutzter Funktion.',
        );
    }

    private function malwareScanner(): ?LaunchBlocker
    {
        $factory = new MalwareScannerFactory;
        $blocker = $factory->productionBlocker();

        if ($blocker === null) {
            return null;
        }

        return new LaunchBlocker(
            self::MALWARE_SCANNER,
            'Uploads',
            $blocker,
            'Hochgeladene Dateien werden nicht auf Schadsoftware geprüft. Das Restrisiko trägt der Betreiber.',
            'Betreiber: Entscheidung für clamav oder external, oder schriftliche Bewertung des Restrisikos.',
        );
    }

    private function legalTexts(): ?LaunchBlocker
    {
        $placeholders = [];

        foreach (self::RECHTSTEXTSEITEN as $file) {
            $path = $this->legalViewPath.DIRECTORY_SEPARATOR.$file;

            if (! is_file($path)) {
                $placeholders[] = $file;

                continue;
            }

            $content = file_get_contents($path);

            if ($content === false || str_contains($content, self::PLATZHALTER_MARKER)) {
                $placeholders[] = $file;
            }
        }

        if ($placeholders === []) {
            return null;
        }

        return new LaunchBlocker(
            self::RECHTSTEXTE,
            'Recht',
            'Die Rechtstexte sind noch Platzhalterfassungen: '.implode(', ', $placeholders).'.',
            'Impressum, Datenschutzerklärung, AGB und Widerrufsbelehrung sind ohne freigegebene Textfassung '
                .'nicht rechtsverbindlich. Ein Livegang wäre abmahnungsgefährdet.',
            'Betreiber über die beauftragte Rechtsanwaltskanzlei. Die Texte werden nicht selbst formuliert.',
        );
    }

    private function corporateIdentity(): ?LaunchBlocker
    {
        foreach (self::CI_DATEIEN as $file) {
            if (is_file($this->ciAssetPath.DIRECTORY_SEPARATOR.$file)) {
                return null;
            }
        }

        return new LaunchBlocker(
            self::CI_ASSETS,
            'Gestaltung',
            'In public/ci fehlt das Logo der Hausverwaltung Müller GmbH. Erwartet wird '
                .implode(' oder ', self::CI_DATEIEN).'.',
            'Oberfläche, Rechnung und PDF zeigen einen neutralen Textplatzhalter statt des Logos.',
            'Betreiber. Es wird kein Logo erzeugt, generiert oder nachgezeichnet.',
        );
    }

    private function extractedDataRetention(): ?LaunchBlocker
    {
        if ($this->configInt('smartabrechnen.retention.extracted_data_days') !== null) {
            return null;
        }

        return new LaunchBlocker(
            self::AUFBEWAHRUNG_EXTRAKTIONSDATEN,
            'Aufbewahrung',
            'Für die strukturierten Extraktionsdaten ist keine Aufbewahrungsfrist festgelegt '
                .'(EXTRACTED_DATA_RETENTION_DAYS ist nicht gesetzt).',
            'Die Daten bleiben unbefristet gespeichert. Das widerspricht dem Grundsatz der Speicherbegrenzung '
                .'und ist in der Datenschutzerklärung nicht belegbar.',
            'Betreiber: kaufmännische und datenschutzrechtliche Entscheidung, dokumentiert im Löschkonzept.',
        );
    }

    private function generatedPdfRetention(): ?LaunchBlocker
    {
        if ($this->configInt('smartabrechnen.retention.generated_pdf_days') !== null) {
            return null;
        }

        return new LaunchBlocker(
            self::AUFBEWAHRUNG_ERGEBNIS_PDF,
            'Aufbewahrung',
            'Für die erzeugten Ergebnis-PDF ist keine Aufbewahrungsfrist festgelegt '
                .'(GENERATED_PDF_RETENTION_DAYS ist nicht gesetzt).',
            'Die Ergebnisdateien bleiben unbefristet gespeichert. Die Frist ist gegen die steuerlichen '
                .'Aufbewahrungspflichten der Rechnungen abzugrenzen.',
            'Betreiber, in Abstimmung mit dem Steuerberater.',
        );
    }

    private function correctionPeriod(): ?LaunchBlocker
    {
        // Der Konfigurationswert hat einen Standard. Entschieden ist die Frist
        // erst, wenn der Betreiber sie ausdruecklich gesetzt hat. Deshalb wird
        // hier die Umgebungsvariable selbst geprueft und nicht ihr Standard.
        $raw = env('PRICE_CORRECTION_FREE_DAYS');

        if (is_string($raw) && trim($raw) !== '') {
            return null;
        }

        if (is_int($raw)) {
            return null;
        }

        return new LaunchBlocker(
            self::KORREKTURFRIST,
            'Preis',
            'Die Korrekturfrist nach der Zahlung ist nicht entschieden '
                .'(PRICE_CORRECTION_FREE_DAYS ist nicht gesetzt).',
            sprintf(
                'Es gilt der Standard von %d Tagen, also keine kostenfreie Korrektur. Ohne ausdrückliche '
                .'Entscheidung ist die Preisangabe gegenüber dem Kunden nicht belastbar.',
                $this->configInt('smartabrechnen.pricing.correction_free_days') ?? 0,
            ),
            'Geschäftsführung, kaufmännische Entscheidung.',
            LaunchBlocker::SCHWERE_ENTSCHEIDUNG,
        );
    }

    // -----------------------------------------------------------------
    // Konfigurationszugriff
    // -----------------------------------------------------------------

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function configInt(string $key): ?int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configArray(string $key): array
    {
        $value = config($key);

        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
