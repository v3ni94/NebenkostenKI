<?php

declare(strict_types=1);

namespace App\Application\Privacy;

/**
 * Verbindliche Ausschlussliste für Backups (Masterprompt 19, ADR-007).
 *
 * Die folgenden Daten dürfen in KEINEM Backup liegen, weder in einem
 * Datenbankdump noch in einem Dateibackup, weder verschlüsselt noch
 * unverschlüsselt:
 *
 *  1. der Kurzzeitbereich mit den Originaluploads,
 *  2. Seitenbilder und Vorschaubilder der Quelldokumente,
 *  3. vollständige OCR-Dateien und Text-Layer,
 *  4. KI-Zwischendaten, also rohe Prompts, rohe Antworten und
 *     zwischengespeicherte Providerdateien,
 *  5. Queue-Payloads und Framework-Caches.
 *
 * Grund: Ein Backup ist der einzige Weg, auf dem eine bereits gelöschte
 * Originaldatei wieder auftauchen könnte. Der Ausschluss ist damit Teil des
 * Löschkonzepts und nicht nur eine Betriebsempfehlung.
 *
 * Die Liste ist Code, damit sie geprüft werden kann. AuditBackupManifest
 * vergleicht sie gegen das Manifest eines Backups und lässt den Lauf mit
 * Fehlercode enden, wenn ein verbotener Pfad enthalten ist.
 */
final class BackupExclusionPolicy
{
    /**
     * Verbotene Pfadbestandteile, je Regel ein sprechender Name.
     *
     * Der Vergleich erfolgt auf normalisierten Pfaden, ohne führendes
     * Verzeichnistrennzeichen und in Kleinschreibung.
     *
     * @var array<string, list<string>>
     */
    private const RULES = [
        'Kurzzeitbereich mit Originaluploads' => [
            'storage/app/temporary-uploads',
            'storage/app/temporary_uploads',
            'temporary-uploads/',
            'temporary_uploads/',
        ],
        'Seitenbilder und Vorschaubilder der Quelldokumente' => [
            'storage/app/seitenbilder',
            'seitenbilder/',
            'page-images/',
            'pagerenders/',
            'thumbnails/',
        ],
        'Vollständige OCR-Dateien und Text-Layer' => [
            'storage/app/ocr',
            'ocr/',
            '.ocr.txt',
            '-ocr.txt',
            'textlayer/',
        ],
        'KI-Zwischendaten' => [
            'storage/app/ki-zwischendaten',
            'ki-zwischendaten/',
            'ai-payloads/',
            'ai-raw/',
            'provider-files/',
        ],
        'Queue-Payloads und Framework-Caches' => [
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'queue-payloads/',
            'jobs-payload',
        ],
    ];

    /**
     * Alle Regeln mit ihren Mustern, für Dokumentation und Prüfbericht.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return self::RULES;
    }

    /**
     * Menschlich lesbare Ausschlussliste.
     *
     * @return list<string>
     */
    public static function ruleNames(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Prüft einen einzelnen Pfad. Rückgabe ist der Name der verletzten Regel
     * oder null, wenn der Pfad zulässig ist.
     */
    public static function violatedRule(string $path): ?string
    {
        $normalisiert = self::normalize($path);

        if ($normalisiert === '') {
            return null;
        }

        foreach (self::RULES as $regel => $muster) {
            foreach ($muster as $teil) {
                if (str_contains($normalisiert, $teil)) {
                    return $regel;
                }
            }
        }

        return null;
    }

    /**
     * Normalisiert einen Pfad: Rückwärtsschrägstriche, führende Trennzeichen
     * und Groß- und Kleinschreibung sollen die Prüfung nicht aushebeln.
     */
    public static function normalize(string $path): string
    {
        $wert = str_replace('\\', '/', trim($path));
        $wert = ltrim($wert, './');
        $wert = preg_replace('#/{2,}#', '/', $wert);

        return mb_strtolower(is_string($wert) ? $wert : '');
    }
}
