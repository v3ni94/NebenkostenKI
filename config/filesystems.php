<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Standard-Disk
    |--------------------------------------------------------------------------
    |
    | Standard fuer erzeugte Ergebnisartefakte ist "sftp". Auf IONOS ist SFTP
    | der verbindliche Speicher fuer Vorschau-PDFs, Final-PDFs, ZIP-Dateien und
    | HVM-Rechnungen. Originaluploads liegen ausschliesslich auf der Disk
    | "temporary_uploads" und werden nach der Auswertung geloescht.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'sftp'),

    'disks' => [

        /*
         * Verschluesselter Quarantaene- und Arbeitsbereich fuer Originaluploads.
         *
         * Verbindliche Eigenschaften:
         * - liegt ausserhalb des Webroots und wird niemals oeffentlich ausgeliefert
         * - wird aus jedem Datei- und Serverbackup ausgeschlossen
         * - wird nach abgeschlossener Extraktion, bei endgueltigem Fehler oder
         *   spaetestens nach TEMP_UPLOAD_TTL_MINUTES automatisch geleert
         *
         * Originaldateien duerfen niemals auf "sftp", "s3" oder "public" liegen.
         */
        'temporary_uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/temporary-uploads'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Speicher fuer vom System erzeugte Ergebnisartefakte.
         * Zugriff nur ueber autorisierte Streaming-Routen oder kurzlebige
         * signierte Links, niemals ueber einen oeffentlichen Pfad.
         */
        'sftp' => [
            'driver' => 'sftp',
            'host' => env('SFTP_HOST'),
            'port' => (int) env('SFTP_PORT', 22),
            'username' => env('SFTP_USERNAME'),
            'password' => env('SFTP_PASSWORD') ?: null,
            'privateKey' => env('SFTP_PRIVATE_KEY_PATH') ?: null,
            'passphrase' => env('SFTP_PASSPHRASE') ?: null,
            'root' => env('SFTP_ROOT', ''),
            'timeout' => (int) env('SFTP_TIMEOUT', 20),
            'visibility' => 'private',
            'directoryVisibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Optionaler EU-kompatibler Objektspeicher, ausschliesslich fuer
         * erzeugte Systemartefakte. Per S3_ENABLED zuschaltbar.
         */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Nur fuer lokale Entwicklung und Tests. Ersetzt in der Testumgebung
         * die SFTP-Disk, damit keine echte Verbindung benoetigt wird.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolische Links
    |--------------------------------------------------------------------------
    |
    | Es wird bewusst kein Link nach public angelegt. Es gibt keine oeffentlich
    | ausgelieferten Nutzerdateien.
    |
    */

    'links' => [],

];
