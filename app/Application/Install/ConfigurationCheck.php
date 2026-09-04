<?php

declare(strict_types=1);

namespace App\Application\Install;

use App\Application\Admin\SystemHealthCheck;
use App\Application\Install\Connectivity\SmtpConnectivity;
use App\Application\Install\Connectivity\SmtpHandshakeConnectivity;
use App\Application\Install\Connectivity\StripeApiConnectivity;
use App\Application\Install\Connectivity\StripeConnectivity;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\Dto\AiRequestContext;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\ProviderReleaseGate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Prueft die hinterlegten Zugangsdaten und Betriebseinstellungen tatsaechlich
 * (Masterprompt 20, 22, 26), ohne Geheimnisse auszugeben.
 *
 * VERBINDLICH
 *
 *  1. Kein Secret, kein Hostname, kein Benutzername und kein Pfad aus der
 *     Konfiguration erscheint in einer Meldung. Bei Fehlern wird nur der
 *     Klassenname der Ausnahme genannt.
 *  2. Jede externe Verbindung laeuft ueber eine austauschbare Stelle:
 *     Datenbank ueber die Laravel-Verbindung, SFTP ueber die Disk, SMTP und
 *     Stripe ueber die Schnittstellen in App\Application\Install\Connectivity,
 *     KI ueber App\Services\Ai\AiProviderRouter. Im Testlauf werden sie
 *     ersetzt; es entsteht keine echte Verbindung.
 *  3. Ohne hinterlegte Zugangsdaten wird nicht verbunden, sondern ein
 *     FEHLER mit Handlungsanweisung gemeldet.
 */
final class ConfigurationCheck
{
    private readonly StripeConnectivity $stripe;

    private readonly SmtpConnectivity $smtp;

    public function __construct(
        private readonly Container $container,
        private readonly SchedulerHeartbeat $heartbeat,
        ?StripeConnectivity $stripe = null,
        ?SmtpConnectivity $smtp = null,
    ) {
        $this->stripe = $stripe ?? new StripeApiConnectivity;
        $this->smtp = $smtp ?? new SmtpHandshakeConnectivity;
    }

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        return [
            $this->environment(),
            $this->debug(),
            $this->appUrl(),
            $this->trustedProxies(),
            $this->database(),
            $this->sftp(),
            $this->smtp(),
            $this->stripeKeys(),
            $this->stripeWebhookSecret(),
            $this->ai(),
            $this->aiPipelineBinding(),
            $this->aiDailyCostLimit(),
            $this->viteManifest(),
            $this->scheduler(),
        ];
    }

    /**
     * @param  list<CheckResult>  $results
     */
    public static function hasErrors(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isError()) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Anwendung
    // -----------------------------------------------------------------

    public function environment(): CheckResult
    {
        $env = $this->configString('app.env') ?? '';

        if ($env === 'production') {
            return CheckResult::ok('APP_ENV', 'Die Umgebung ist "production".');
        }

        if ($env === 'staging') {
            return CheckResult::warning(
                'APP_ENV',
                'Die Umgebung ist "staging". ForceHttps und HSTS greifen nur in "production".',
                'Fuer den Produktivbetrieb APP_ENV=production setzen.',
            );
        }

        return CheckResult::error(
            'APP_ENV',
            sprintf('Die Umgebung ist "%s".', $env),
            'In der .env APP_ENV=production setzen, danach "php artisan smartabrechnen:install" erneut ausfuehren.',
        );
    }

    public function debug(): CheckResult
    {
        if (config('app.debug') === false) {
            return CheckResult::ok('APP_DEBUG', 'Der Debugmodus ist abgeschaltet.');
        }

        return CheckResult::error(
            'APP_DEBUG',
            'Der Debugmodus ist eingeschaltet. Fehlerseiten wuerden Konfiguration und Daten offenlegen.',
            'In der .env APP_DEBUG=false setzen und die Konfiguration neu zwischenspeichern.',
        );
    }

    public function appUrl(): CheckResult
    {
        $url = $this->configString('app.url');

        if ($url === null) {
            return CheckResult::error('APP_URL', 'APP_URL ist nicht gesetzt.', 'In der .env APP_URL=https://<domain> eintragen.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            return CheckResult::error(
                'APP_URL',
                'APP_URL verwendet nicht https oder enthaelt keinen Hostnamen.',
                'APP_URL auf https://<kanonische Domain> setzen. Signierte Links und Cookies setzen HTTPS voraus.',
            );
        }

        if (str_starts_with(strtolower($host), 'www.')) {
            return CheckResult::warning(
                'APP_URL',
                'APP_URL beginnt mit www. Die www-Umleitung ist dann wirkungslos.',
                'Die kanonische Domain ohne www in APP_URL eintragen, siehe Masterprompt 3.1.',
            );
        }

        return CheckResult::ok('APP_URL', 'APP_URL verwendet https und eine kanonische Domain.');
    }

    public function trustedProxies(): CheckResult
    {
        $value = config('deploy.trusted_proxies');

        if (TrustedProxyConfiguration::isConfigured($value)) {
            return CheckResult::ok('Trusted Proxies', 'TRUSTED_PROXIES ist gesetzt.');
        }

        return CheckResult::warning(
            'Trusted Proxies',
            'TRUSTED_PROXIES ist leer. Hinter dem IONOS-Proxy wird HTTPS nicht erkannt, die Client-IP ist die Proxy-Adresse.',
            'Auf IONOS Webhosting TRUSTED_PROXIES=* setzen, auf einem eigenen Server die Adressen des Proxys. Siehe config/deploy.php.',
        );
    }

    // -----------------------------------------------------------------
    // Datenbank
    // -----------------------------------------------------------------

    public function database(): CheckResult
    {
        try {
            $pdo = DB::connection()->getPdo();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $version = is_scalar($version) ? (string) $version : '';
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_scalar($driver) ? (string) $driver : '';
        } catch (Throwable $exception) {
            return CheckResult::error(
                'Datenbank',
                'Die Verbindung ist fehlgeschlagen ('.class_basename($exception).').',
                'DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME und DB_PASSWORD gegen die Angaben im IONOS-Control-Center pruefen.',
            );
        }

        if ($driver === 'sqlite') {
            return CheckResult::warning(
                'Datenbank',
                'Die Verbindung nutzt SQLite '.$version.'.',
                'Zielsystem ist MariaDB 10.11 oder 11.x. SQLite ist nur fuer lokale Tests zulaessig.',
            );
        }

        if (! Str::contains(Str::lower($version), 'mariadb')) {
            return CheckResult::error(
                'Datenbank',
                sprintf('Der Server meldet sich als MySQL %s, nicht als MariaDB.', $version),
                'MySQL ist nach ADR-003 ein Blocker. Eine MariaDB 10.11 oder 11.x im IONOS-Konto oder als externe Datenbank bereitstellen.',
            );
        }

        if (! SystemHealthCheck::isSupportedMariaDbVersion($version)) {
            return CheckResult::warning(
                'Datenbank',
                sprintf('MariaDB %s ist nicht als unterstuetzte Version hinterlegt.', $version),
                'Verbindlich sind MariaDB 10.11 LTS oder 11.x. Version im IONOS-Control-Center pruefen.',
            );
        }

        return CheckResult::ok('Datenbank', sprintf('Verbunden mit MariaDB %s.', $version));
    }

    // -----------------------------------------------------------------
    // SFTP
    // -----------------------------------------------------------------

    public function sftp(): CheckResult
    {
        $disk = $this->configString('filesystems.default') ?? 'sftp';

        if ($disk !== 'sftp') {
            return CheckResult::warning(
                'SFTP',
                sprintf('Die Ergebnisablage nutzt die Disk "%s", nicht SFTP.', $disk),
                'Produktiv FILESYSTEM_DISK=sftp setzen und die SFTP_*-Variablen befuellen (Masterprompt 3.4).',
            );
        }

        $host = $this->configString('filesystems.disks.sftp.host');
        $user = $this->configString('filesystems.disks.sftp.username');
        $password = $this->configString('filesystems.disks.sftp.password');
        $key = $this->configString('filesystems.disks.sftp.privateKey');

        if ($host === null || $user === null) {
            return CheckResult::error(
                'SFTP',
                'SFTP_HOST oder SFTP_USERNAME sind nicht gesetzt.',
                'Zugangsdaten aus dem IONOS-Control-Center (SFTP-Zugaenge) in die .env eintragen.',
            );
        }

        if ($password === null && $key === null) {
            return CheckResult::error(
                'SFTP',
                'Weder SFTP_PASSWORD noch SFTP_PRIVATE_KEY_PATH sind gesetzt.',
                'Genau einen Authentifizierungsweg hinterlegen.',
            );
        }

        if ($password !== null && $key !== null) {
            return CheckResult::warning(
                'SFTP',
                'Passwort und privater Schluessel sind gleichzeitig gesetzt.',
                'Nur einen Weg aktivieren (Masterprompt 3.4).',
            );
        }

        $probe = 'check-config/'.Str::lower((string) Str::ulid()).'.txt';

        try {
            $filesystem = Storage::disk('sftp');
            $filesystem->put($probe, 'ok');
            $readable = $filesystem->get($probe) === 'ok';
            $filesystem->delete($probe);
            $deleted = ! $filesystem->exists($probe);
        } catch (Throwable $exception) {
            return CheckResult::error(
                'SFTP',
                'Schreib- und Loeschprobe fehlgeschlagen ('.class_basename($exception).').',
                'Host, Port, Benutzer, Passwort oder Schluessel und den Zielpfad SFTP_ROOT pruefen. Der Zielpfad muss existieren und beschreibbar sein.',
            );
        }

        if (! $readable || ! $deleted) {
            return CheckResult::error(
                'SFTP',
                'Die Probedatei konnte nicht zurueckgelesen oder nicht geloescht werden.',
                'Rechte des Zielpfads SFTP_ROOT pruefen (lesen, schreiben, loeschen).',
            );
        }

        return CheckResult::ok('SFTP', 'Schreiben, Lesen und Loeschen einer Probedatei im Zielpfad ist moeglich.');
    }

    // -----------------------------------------------------------------
    // SMTP
    // -----------------------------------------------------------------

    public function smtp(): CheckResult
    {
        $mailer = $this->configString('mail.default') ?? 'smtp';

        if (in_array($mailer, ['array', 'log', 'null'], true)) {
            return CheckResult::error(
                'SMTP',
                sprintf('Der Versandtreiber ist "%s". Es verlaesst keine Nachricht das System.', $mailer),
                'MAIL_MAILER=smtp und die MAIL_*-Variablen des IONOS-Postfachs setzen.',
            );
        }

        $config = config('mail.mailers.'.$mailer);

        if (! is_array($config)) {
            return CheckResult::error('SMTP', 'Die Mailerkonfiguration fehlt.', 'MAIL_MAILER pruefen.');
        }

        /** @var array<string, mixed> $config */
        $missing = [];

        foreach (['host', 'port', 'username', 'password'] as $key) {
            $value = $config[$key] ?? null;

            if ($value === null || $value === '') {
                $missing[] = 'MAIL_'.strtoupper($key);
            }
        }

        if ($this->configString('mail.from.address') === null) {
            $missing[] = 'MAIL_FROM_ADDRESS';
        }

        if ($missing !== []) {
            return CheckResult::error(
                'SMTP',
                'Die Versandkonfiguration ist unvollstaendig: '.implode(', ', $missing).'.',
                'Die genannten Variablen mit den Angaben des IONOS-Postfachs befuellen.',
            );
        }

        try {
            $this->smtp->handshake($config);
        } catch (Throwable $exception) {
            return CheckResult::error(
                'SMTP',
                'Handshake oder Anmeldung fehlgeschlagen ('.class_basename($exception).').',
                'MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME und MAIL_PASSWORD pruefen. IONOS: smtp.ionos.de, Port 465 mit MAIL_SCHEME=smtps oder Port 587 mit MAIL_SCHEME=smtp (STARTTLS).',
            );
        }

        return CheckResult::ok('SMTP', 'Verbindung, Handshake und Anmeldung am Postausgangsserver erfolgreich.');
    }

    // -----------------------------------------------------------------
    // Stripe
    // -----------------------------------------------------------------

    public function stripeKeys(): CheckResult
    {
        $secret = $this->configString('services.stripe.secret');
        $public = $this->configString('services.stripe.key');

        if ($secret === null || $public === null) {
            return CheckResult::error(
                'Stripe-Schluessel',
                'STRIPE_SECRET oder STRIPE_KEY sind nicht gesetzt.',
                'Beide Schluessel aus dem Stripe-Dashboard (Entwickler, API-Schluessel) in die .env eintragen.',
            );
        }

        try {
            $mode = $this->stripe->verifySecretKey($secret);
        } catch (Throwable $exception) {
            return CheckResult::error(
                'Stripe-Schluessel',
                'Der geheime Schluessel wurde von Stripe nicht akzeptiert ('.class_basename($exception).').',
                'STRIPE_SECRET pruefen. Der Schluessel muss zum selben Konto und Modus gehoeren wie STRIPE_KEY.',
            );
        }

        $publicMode = str_starts_with($public, 'pk_live_') ? 'live' : (str_starts_with($public, 'pk_test_') ? 'test' : 'unbekannt');

        if ($publicMode !== $mode) {
            return CheckResult::warning(
                'Stripe-Schluessel',
                sprintf('Der geheime Schluessel ist im Modus "%s", der veroeffentlichbare im Modus "%s".', $mode, $publicMode),
                'Beide Schluessel aus demselben Modus verwenden.',
            );
        }

        if ($mode === 'test' && $this->configString('app.env') === 'production') {
            return CheckResult::warning(
                'Stripe-Schluessel',
                'Die Schluessel sind Testschluessel, die Umgebung ist production.',
                'Vor dem Livegang die Live-Schluessel eintragen. Fuer die Staging-Abnahme sind Testschluessel richtig.',
            );
        }

        return CheckResult::ok('Stripe-Schluessel', sprintf('Der geheime Schluessel ist gueltig (Modus "%s").', $mode));
    }

    public function stripeWebhookSecret(): CheckResult
    {
        $secret = $this->configString('services.stripe.webhook_secret');

        if ($secret === null) {
            return CheckResult::error(
                'Stripe-Webhook',
                'STRIPE_WEBHOOK_SECRET ist nicht gesetzt. Jede Benachrichtigung wuerde verworfen.',
                'Im Stripe-Dashboard einen Endpunkt fuer '.($this->configString('app.url') ?? 'https://<domain>').'/webhooks/stripe anlegen und das Signing Secret eintragen.',
            );
        }

        if (! str_starts_with($secret, 'whsec_')) {
            return CheckResult::warning(
                'Stripe-Webhook',
                'STRIPE_WEBHOOK_SECRET hat nicht das erwartete Format.',
                'Das Signing Secret des Endpunkts beginnt mit whsec_. Wert im Stripe-Dashboard pruefen.',
            );
        }

        return CheckResult::ok('Stripe-Webhook', 'Das Webhook-Secret ist gesetzt.');
    }

    // -----------------------------------------------------------------
    // KI-Provider
    // -----------------------------------------------------------------

    public function ai(): CheckResult
    {
        try {
            $config = AiConfig::fromArray($this->configArray('ai'));
        } catch (Throwable $exception) {
            return CheckResult::error(
                'KI-Provider',
                'Die KI-Konfiguration ist unvollstaendig ('.class_basename($exception).').',
                'AI_PRIMARY_PROVIDER, Modelle und API-Schluessel in der .env pruefen.',
            );
        }

        if ($config->primaryProvider === AiProviderKey::FAKE) {
            return CheckResult::error(
                'KI-Provider',
                'Als Primaerprovider ist der Testprovider eingestellt.',
                'AI_PRIMARY_PROVIDER auf openai oder anthropic setzen.',
            );
        }

        $gate = new ProviderReleaseGate($config->requireZeroDataRetention, $config->dataRetentionApproved, 'production');
        $blockReason = $gate->blockReason($config->primaryProvider);

        if ($blockReason !== null) {
            return CheckResult::warning(
                'KI-Provider',
                'Die Datenschutzfreigabe fehlt, der Healthcheck wurde nicht ausgefuehrt. '.$blockReason,
                'Nach Auftragsverarbeitungsvertrag und Retention-Nachweis AI_DATA_RETENTION_APPROVED=true setzen, dann erneut pruefen.',
            );
        }

        try {
            $router = $this->container->make(AiProviderRouter::class);
            $result = $router->healthCheck(new HealthCheckRequest(
                AiRequestContext::forCorrelation('check-config-'.Str::lower((string) Str::ulid())),
            ));
        } catch (Throwable $exception) {
            return CheckResult::error(
                'KI-Provider',
                'Der Healthcheck ist fehlgeschlagen ('.class_basename($exception).').',
                'API-Schluessel und Netzzugriff pruefen.',
            );
        }

        if (! $result->apiKeyConfigured) {
            return CheckResult::error(
                'KI-Provider',
                sprintf('Fuer den Provider "%s" ist kein API-Schluessel gesetzt.', $result->providerKey),
                'OPENAI_API_KEY beziehungsweise ANTHROPIC_API_KEY in der .env eintragen.',
            );
        }

        if (! $result->reachable || ! $result->modelAvailable) {
            return CheckResult::error(
                'KI-Provider',
                sprintf(
                    'Provider "%s": erreichbar %s, Modell "%s" verfuegbar %s.',
                    $result->providerKey,
                    $result->reachable ? 'ja' : 'nein',
                    $result->model,
                    $result->modelAvailable ? 'ja' : 'nein',
                ),
                'API-Schluessel, Projektberechtigungen und den Modellnamen in der .env gegen die Modellliste des Providers pruefen.',
            );
        }

        return CheckResult::ok('KI-Provider', sprintf('Provider "%s" erreichbar, Modell "%s" verfuegbar.', $result->providerKey, $result->model));
    }

    /**
     * Verdrahtung der KI-Schicht mit der Dokumentpipeline.
     *
     * Ein ausdrueckliches false ist der Notschalter fuer den Betrieb. Er ist
     * zulaessig, muss dem Betreiber aber sichtbar sein, weil sonst jeder Upload
     * ohne Auswertung im Dead Letter endet und niemand die Ursache erkennt.
     */
    public function aiPipelineBinding(): CheckResult
    {
        if (config('ai.bind_document_pipeline') === false) {
            return CheckResult::warning(
                'KI-Anbindung',
                'Die automatische Auswertung ruht: AI_BIND_DOCUMENT_PIPELINE steht auf false. Uploads laufen ohne Auswertung in den Fehlerpfad.',
                'Fuer den Regelbetrieb die Zeile AI_BIND_DOCUMENT_PIPELINE aus der .env entfernen oder auf true setzen und die Konfiguration neu zwischenspeichern.',
            );
        }

        return CheckResult::ok('KI-Anbindung', 'Die Dokumentpipeline ist mit der KI-Schicht verbunden.');
    }

    /**
     * Tagesbudget je Nutzer und die dafuer notwendige Kalkulationsbasis.
     *
     * Ein Limit von 0 oder kleiner wuerde jede Auswertung als "Tageslimit
     * erreicht" abweisen, ohne dass ein Cent verbraucht wurde. Ein Limit ohne
     * Kalkulationsbasis fuer die konfigurierten Modelle ist wirkungslos, weil
     * kein Preis geraten wird und die Aufrufe ungezaehlt durchlaufen.
     */
    public function aiDailyCostLimit(): CheckResult
    {
        $limit = config('ai.max_daily_cost_cent_per_user');

        if ($limit === null) {
            return CheckResult::warning(
                'KI-Tageslimit',
                'Es ist kein Tagesbudget je Nutzer gesetzt (AI_MAX_DAILY_COST_CENT_PER_USER ist leer).',
                'Vor Livegang entscheiden: Tagesbudget in ganzen Cent eintragen oder bewusst ohne Limit betreiben.',
            );
        }

        if (! is_numeric($limit) || (int) $limit <= 0) {
            return CheckResult::error(
                'KI-Tageslimit',
                sprintf('Das Tagesbudget je Nutzer ist mit "%s" ungueltig. Jede Auswertung wuerde als "Tageslimit erreicht" abgewiesen.', is_scalar($limit) ? (string) $limit : gettype($limit)),
                'AI_MAX_DAILY_COST_CENT_PER_USER auf einen Betrag in ganzen Cent groesser 0 setzen oder die Zeile fuer "kein Limit" leer lassen.',
            );
        }

        $missing = $this->modelsWithoutCostBasis();

        if ($missing !== []) {
            return CheckResult::error(
                'KI-Tageslimit',
                'Fuer folgende konfigurierte Modelle fehlt die Kalkulationsbasis, das Tageslimit greift fuer sie nicht: '.implode(', ', $missing).'.',
                'In config/ai.php unter cost_basis_us_cent_per_million_tokens die Preise aus der offiziellen Preisliste des Providers eintragen.',
            );
        }

        return CheckResult::ok('KI-Tageslimit', sprintf('Tagesbudget je Nutzer: %d Cent, Kalkulationsbasis fuer alle konfigurierten Modelle vorhanden.', (int) $limit));
    }

    /**
     * Konfigurierte Modelle des Primaer- und Fallbackproviders ohne
     * Kalkulationsbasis, als "provider: modell".
     *
     * @return list<string>
     */
    private function modelsWithoutCostBasis(): array
    {
        try {
            $config = AiConfig::fromArray($this->configArray('ai'));
        } catch (Throwable) {
            return [];
        }

        $estimator = CostEstimator::fromConfig($config);
        $keys = [$config->primaryProvider];

        if ($config->fallbackEnabled && $config->fallbackProvider !== null) {
            $keys[] = $config->fallbackProvider;
        }

        $missing = [];

        foreach (array_unique($keys, SORT_REGULAR) as $key) {
            if ($key === AiProviderKey::FAKE) {
                continue;
            }

            $provider = $config->provider($key);

            if ($provider === null) {
                continue;
            }

            foreach (array_unique([$provider->modelExtract, $provider->modelAnalyze]) as $model) {
                if (! $estimator->hasBasisFor($model)) {
                    $missing[] = $key->value.': '.$model;
                }
            }
        }

        return $missing;
    }

    // -----------------------------------------------------------------
    // Assets und Scheduler
    // -----------------------------------------------------------------

    public function viteManifest(): CheckResult
    {
        $manifest = public_path('build/manifest.json');

        if (is_file($manifest)) {
            return CheckResult::ok('Assets', 'Das Vite-Manifest liegt vor, die Assets sind gebaut.');
        }

        return CheckResult::error(
            'Assets',
            'public/build/manifest.json fehlt. Die Seiten wuerden ohne Stylesheet und Skripte ausgeliefert.',
            'Lokal oder in der CI "npm ci && npm run build" ausfuehren und public/build mit ausliefern.',
        );
    }

    public function scheduler(): CheckResult
    {
        $last = $this->heartbeat->lastRun();

        if ($last === null) {
            return CheckResult::error(
                'Cronjob',
                'Es wurde noch kein Schedulerlauf registriert.',
                'Im IONOS-Control-Center den Cronjob "<php> <pfad>/artisan schedule:run" jede Minute einrichten, siehe docs/betrieb/installation.md.',
            );
        }

        if ($this->heartbeat->isStale()) {
            return CheckResult::error(
                'Cronjob',
                sprintf('Der letzte Schedulerlauf war am %s und liegt zu lange zurueck.', $last->timezone('Europe/Berlin')->format('d.m.Y H:i')),
                'Cronjob im IONOS-Control-Center pruefen: Kommando, PHP-Binary, absoluter Pfad und Aktivierung.',
            );
        }

        return CheckResult::ok('Cronjob', sprintf('Letzter Schedulerlauf am %s.', $last->timezone('Europe/Berlin')->format('d.m.Y H:i')));
    }

    // -----------------------------------------------------------------
    // Konfigurationszugriff
    // -----------------------------------------------------------------

    private function configString(string $key): ?string
    {
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configArray(string $key): array
    {
        $value = config($key);

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
