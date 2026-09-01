<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Enums\GeneratedDocumentKind;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\BillingRunVersion;
use App\Models\CalculationSnapshot;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentRelation;
use App\Models\EmailMessage;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Models\HeatingStatement;
use App\Models\HeatingStatementLine;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Landlord;
use App\Models\LegalAcceptance;
use App\Models\ManualOverride;
use App\Models\MeterDevice;
use App\Models\MeterReading;
use App\Models\OccupancyPeriod;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\ReminderEvent;
use App\Models\ReminderPreference;
use App\Models\SourceDeletionEvent;
use App\Models\Tenancy;
use App\Models\TenancyPerson;
use App\Models\Unit;
use App\Models\UnitStatement;
use App\Models\UnitStatementLine;
use App\Models\User;
use App\Models\VacancyPeriod;
use App\Models\ValidationIssue;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use ZipArchive;

/**
 * Baut den DSGVO-Datenexport eines Kontos als ZIP.
 *
 * VERBINDLICHE EIGENSCHAFTEN (Masterprompt 19, ARCHITECTURE.md 5.2 und T1):
 *
 * 1. KEINE ORIGINALDATEIEN. Der Export enthält keine hochgeladene Quelldatei,
 *    kein Seitenbild und keinen vollständigen OCR-Text, weil diese Daten nach
 *    der Auswertung nicht mehr existieren. Aufgenommen werden ausschließlich
 *    die strukturierten Daten und die vom System erzeugten PDFs.
 * 2. KEINE FREMDDATEN. Jede Query ist über organization_id auf die
 *    Organisationen des anfordernden Nutzers gescopet. Es gibt keinen
 *    ungescopten Zugriff.
 * 3. MASCHINENLESBAR. Je Entität eine JSON-Datei mit UTF-8 und unescapten
 *    Zeichen, dazu eine lesbare Übersicht als Textdatei.
 * 4. Die Auslieferung ist nicht Aufgabe dieser Klasse. Sie erzeugt nur die
 *    Bytefolge; Ablage und Auslieferung laufen über ArtifactStorage und eine
 *    autorisierte beziehungsweise signierte Route.
 */
final class DataExportBuilder
{
    /**
     * Über organization_id scopebare Entitäten des Kontos.
     *
     * @var array<string, class-string<Model>>
     */
    private const ORGANIZATION_SCOPED = [
        'vermieter' => Landlord::class,
        'objekte' => Property::class,
        'einheiten' => Unit::class,
        'mietverhaeltnisse' => Tenancy::class,
        'mietparteien' => TenancyPerson::class,
        'belegungszeitraeume' => OccupancyPeriod::class,
        'leerstandszeitraeume' => VacancyPeriod::class,
        'zaehler' => MeterDevice::class,
        'zaehlerstaende' => MeterReading::class,
        'abrechnungslaeufe' => BillingRun::class,
        'abrechnungsstaende' => BillingRunVersion::class,
        'berechnungsstaende' => CalculationSnapshot::class,
        'quellenverzeichnis' => Document::class,
        'dokumentseiten' => DocumentPage::class,
        'ausgelesene_felder' => ExtractedField::class,
        'dokumentbeziehungen' => DocumentRelation::class,
        'kostenpositionen' => CostItem::class,
        'verteilerschluessel' => AllocationKey::class,
        'verteilerschluesselwerte' => AllocationKeyValue::class,
        'vorauszahlungen' => Prepayment::class,
        'heizkostenabrechnungen' => HeatingStatement::class,
        'heizkostenzeilen' => HeatingStatementLine::class,
        'mieterabrechnungen' => UnitStatement::class,
        'abrechnungszeilen' => UnitStatementLine::class,
        'pruefhinweise' => ValidationIssue::class,
        'manuelle_aenderungen' => ManualOverride::class,
        'zahlungen' => Payment::class,
        'rechnungen' => Invoice::class,
        'erzeugte_dokumente' => GeneratedDocument::class,
        'nachrichten' => EmailMessage::class,
        'erinnerungseinstellungen' => ReminderPreference::class,
        'erinnerungsereignisse' => ReminderEvent::class,
        'rechtsnachweise' => LegalAcceptance::class,
        'revisionsprotokoll' => AuditLog::class,
        'loeschnachweise' => SourceDeletionEvent::class,
    ];

    /**
     * Artefaktarten, die in den Export aufgenommen werden.
     *
     * ZIP-Pakete und frühere Datenexporte bleiben bewusst außen vor, damit ein
     * Export nicht seine eigenen Vorgänger mitschleppt.
     *
     * @var list<GeneratedDocumentKind>
     */
    private const EXPORTED_ARTIFACT_KINDS = [
        GeneratedDocumentKind::MIETERABRECHNUNG,
        GeneratedDocumentKind::EIGENTUEMERUEBERSICHT,
        GeneratedDocumentKind::ANLAGE_35A,
        GeneratedDocumentKind::HVM_RECHNUNG,
    ];

    public function __construct(private readonly ArtifactStorage $artifacts) {}

    /**
     * Erzeugt die ZIP-Bytefolge des Exports.
     *
     * @return array{contents: string, entries: list<string>, counts: array<string, int>}
     */
    public function build(User $user): array
    {
        $organizationIds = $user->organizationIds();

        $daten = $this->collect($user, $organizationIds);
        $zaehler = [];

        foreach ($daten as $name => $zeilen) {
            $zaehler[$name] = count($zeilen);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'sa-datenexport-');

        if ($tempFile === false) {
            throw new RuntimeException('Für den Datenexport konnte keine temporäre Datei angelegt werden.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);

            throw new RuntimeException('Das ZIP des Datenexports konnte nicht geöffnet werden.');
        }

        $entries = [];

        $zip->addFromString('LIESMICH.txt', $this->readableOverview($user, $zaehler));
        $entries[] = 'LIESMICH.txt';

        foreach ($daten as $name => $zeilen) {
            $pfad = 'daten/'.$name.'.json';
            $zip->addFromString($pfad, $this->json($zeilen));
            $entries[] = $pfad;
        }

        foreach ($this->artifactEntries($organizationIds) as $pfad => $inhalt) {
            $zip->addFromString($pfad, $inhalt);
            $entries[] = $pfad;
        }

        $zip->close();

        $contents = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($contents === false || ! str_starts_with($contents, "PK\x03\x04")) {
            throw new RuntimeException('Der Datenexport wurde nicht als gültiges ZIP erzeugt.');
        }

        return ['contents' => $contents, 'entries' => $entries, 'counts' => $zaehler];
    }

    /**
     * Sammelt alle Datensätze des Kontos.
     *
     * @param  list<string>  $organizationIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function collect(User $user, array $organizationIds): array
    {
        $ergebnis = [];

        $ergebnis['konto'] = [$this->row($user)];

        $ergebnis['organisationen'] = $organizationIds === []
            ? []
            : $this->rows(Organization::query()->whereIn('id', $organizationIds)->get()->all());

        $ergebnis['mitgliedschaften'] = $this->rows(
            OrganizationUser::query()->where('user_id', $user->getKey())->get()->all()
        );

        foreach (self::ORGANIZATION_SCOPED as $name => $model) {
            $ergebnis[$name] = $organizationIds === []
                ? []
                : $this->rows($model::query()->whereIn('organization_id', $organizationIds)->get()->all());
        }

        // Rechnungspositionen hängen an der Rechnung und tragen selbst keine
        // Mandantenspalte. Sie werden deshalb über die eigenen Rechnungen
        // gescopet, nicht über eine ungescopte Query.
        $rechnungsIds = $organizationIds === []
            ? []
            : Invoice::query()->whereIn('organization_id', $organizationIds)->pluck('id')->all();

        $ergebnis['rechnungspositionen'] = $rechnungsIds === []
            ? []
            : $this->rows(InvoiceItem::query()->whereIn('invoice_id', $rechnungsIds)->get()->all());

        return $ergebnis;
    }

    /**
     * @param  list<Model>  $models
     * @return list<array<string, mixed>>
     */
    private function rows(array $models): array
    {
        $zeilen = [];

        foreach ($models as $model) {
            $zeilen[] = $this->row($model);
        }

        return $zeilen;
    }

    /**
     * Spalten, die niemals in einen Datenexport gelangen.
     *
     * Die Hashes der Wiederherstellungscodes gehoeren ausdruecklich dazu. Sie
     * sind zwar gehasht, aber ein Zweitfaktor-Geheimnis bleibt ein Geheimnis
     * und hat in einer Datei, die das Konto nach draussen weitergibt, nichts
     * zu suchen.
     *
     * @var list<string>
     */
    private const GEHEIME_SPALTEN = [
        'password',
        'password_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, mixed>
     */
    private function row(Model $model): array
    {
        /** @var array<string, mixed> $zeile */
        $zeile = $model->attributesToArray();

        // Zugangsdaten und Geheimnisse gehören in keinen Export.
        foreach (self::GEHEIME_SPALTEN as $spalte) {
            unset($zeile[$spalte]);
        }

        // Zusaetzliche Absicherung gegen spaeter ergaenzte Spalten: alles, was
        // ein Passwort oder ein Geheimnis benennt, wird entfernt, auch wenn es
        // in der Liste oben noch fehlt. Ein Export ist ein Auslieferungsweg
        // nach draussen, deshalb ist hier eine Positivliste zu starr und eine
        // reine Namensliste zu leicht zu vergessen.
        foreach (array_keys($zeile) as $spalte) {
            if (str_contains($spalte, 'password')
                || str_ends_with($spalte, '_secret')
                || str_ends_with($spalte, '_recovery_codes')) {
                unset($zeile[$spalte]);
            }
        }

        return $zeile;
    }

    /**
     * @param  list<array<string, mixed>>  $zeilen
     */
    private function json(array $zeilen): string
    {
        $json = json_encode(
            $zeilen,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '[]' : $json;
    }

    /**
     * Erzeugte PDFs des Kontos, nach Pfad im ZIP.
     *
     * @param  list<string>  $organizationIds
     * @return array<string, string>
     */
    private function artifactEntries(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $arten = array_map(
            static fn (GeneratedDocumentKind $kind): string => $kind->value,
            self::EXPORTED_ARTIFACT_KINDS
        );

        /** @var list<GeneratedDocument> $dokumente */
        $dokumente = GeneratedDocument::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('kind', $arten)
            ->orderBy('generated_at')
            ->get()
            ->all();

        $eintraege = [];
        $laufend = 0;

        foreach ($dokumente as $dokument) {
            $pfad = $dokument->getAttribute('storage_path');

            if (! is_string($pfad) || $pfad === '') {
                continue;
            }

            $inhalt = $this->artifacts->get($pfad);

            if (! is_string($inhalt) || $inhalt === '') {
                continue;
            }

            $laufend++;
            $art = $dokument->getAttribute('kind');
            $ordner = $art instanceof GeneratedDocumentKind ? $this->folder($art) : 'dokumente';

            $eintraege[sprintf('%s/%03d-%s.pdf', $ordner, $laufend, (string) $dokument->getKey())] = $inhalt;
        }

        return $eintraege;
    }

    private function folder(GeneratedDocumentKind $kind): string
    {
        return match ($kind) {
            GeneratedDocumentKind::HVM_RECHNUNG => 'rechnungen-hvm',
            GeneratedDocumentKind::MIETERABRECHNUNG => 'abrechnungen',
            GeneratedDocumentKind::EIGENTUEMERUEBERSICHT => 'eigentuemeruebersichten',
            GeneratedDocumentKind::ANLAGE_35A => 'anlagen-35a',
            default => 'dokumente',
        };
    }

    /**
     * Lesbare Übersicht als Textdatei.
     *
     * @param  array<string, int>  $zaehler
     */
    private function readableOverview(User $user, array $zaehler): string
    {
        $zeilen = [];
        $zeilen[] = 'Datenexport Smart Abrechnen';
        $zeilen[] = '===========================';
        $zeilen[] = '';
        $zeilen[] = 'Erstellt am: '.now()->timezone('Europe/Berlin')->format('d.m.Y H:i');
        $zeilen[] = 'Konto: '.(string) $user->getAttribute('name');
        $zeilen[] = 'E-Mail-Adresse: '.(string) $user->getAttribute('email');
        $zeilen[] = '';
        $zeilen[] = 'Aufbau des Pakets';
        $zeilen[] = '-----------------';
        $zeilen[] = 'LIESMICH.txt      diese Übersicht';
        $zeilen[] = 'daten/*.json      alle Daten Ihres Kontos in maschinenlesbarer Form, je Entität eine Datei';
        $zeilen[] = 'abrechnungen/     Ihre erzeugten Mieterabrechnungen als PDF';
        $zeilen[] = 'rechnungen-hvm/   die Rechnungen der Hausverwaltung Müller GmbH als PDF';
        $zeilen[] = '';
        $zeilen[] = 'Datensätze je Entität';
        $zeilen[] = '---------------------';

        foreach ($zaehler as $name => $anzahl) {
            $zeilen[] = sprintf('%-28s %d', $name, $anzahl);
        }

        $zeilen[] = '';
        $zeilen[] = 'Was dauerhaft gespeichert wird';
        $zeilen[] = '------------------------------';

        foreach (PrivacyDisclosure::storedPermanently() as $punkt) {
            $zeilen[] = '- '.$punkt;
        }

        $zeilen[] = '';
        $zeilen[] = 'Was nicht dauerhaft gespeichert wird';
        $zeilen[] = '------------------------------------';

        foreach (PrivacyDisclosure::neverStoredPermanently() as $punkt) {
            $zeilen[] = '- '.$punkt;
        }

        $zeilen[] = '';
        $zeilen[] = 'Wichtiger Hinweis zu Ihren Originalbelegen';
        $zeilen[] = '-----------------------------------------';
        $zeilen[] = PrivacyDisclosure::ownRecordsNotice();
        $zeilen[] = '';
        $zeilen[] = 'Dieses Paket enthält deshalb keine Ihrer hochgeladenen Originaldateien. Es enthält '
            .'ausschließlich die strukturierten Daten Ihres Kontos und die vom System erzeugten PDFs.';
        $zeilen[] = '';

        return implode("\n", $zeilen);
    }
}
