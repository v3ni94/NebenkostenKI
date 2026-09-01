<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Fachliche Konfiguration von Smart Abrechnen
|------------------------------------------------------------------------------
|
| Alle Geldbetraege sind Integer in Cent. Alle Werte sind ausschliesslich ueber
| Umgebungsvariablen konfigurierbar. Keine Zugangsdaten in dieser Datei.
|
| Aenderungen an Preisen, Regeln oder Prompts wirken nur auf neue
| Berechnungsstaende. Historische Calculation Snapshots bleiben reproduzierbar.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Betreiberangaben
    |--------------------------------------------------------------------------
    |
    | Pflichtangaben der Hausverwaltung Mueller GmbH fuer Impressum und
    | HVM-Rechnung. Diese Angaben werden nicht eigenstaendig ergaenzt oder
    | veraendert. Fehlende Steuer- und Bankdaten sind ein Livegang-Blocker.
    |
    */
    'operator' => [
        'legal_name' => env('HVM_LEGAL_NAME', 'Hausverwaltung Müller GmbH'),
        'address_line' => env('HVM_ADDRESS_LINE', 'Rheinpromenade 13'),
        'postal_code' => env('HVM_POSTAL_CODE', '40789'),
        'city' => env('HVM_CITY', 'Monheim am Rhein'),
        'register_court' => env('HVM_REGISTER_COURT', 'Amtsgericht Düsseldorf'),
        'register_number' => env('HVM_REGISTER_NUMBER', 'HRB 104762'),
        'managing_director' => env('HVM_MANAGING_DIRECTOR', 'Timo Müller'),
        'website' => env('HVM_WEBSITE', 'https://www.muellerhv.de/'),
        'tax_id' => env('HVM_TAX_ID'),
        'vat_id' => env('HVM_VAT_ID'),
        'iban' => env('HVM_IBAN'),
        'bic' => env('HVM_BIC'),
        'masterdata_confirmed' => (bool) env('HVM_MASTERDATA_CONFIRMED', false),
        'placeholder_text' => '[vor Livegang ergänzen]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Preislogik
    |--------------------------------------------------------------------------
    |
    | Abrechnungseinheit fuer den Preis ist eine erzeugte Mieterabrechnung,
    | nicht eine Wohnung. Bei Mieterwechsel entstehen je Einheit mehrere
    | Mieterabrechnungen. Verbraucherpreise werden brutto angezeigt.
    |
    | Der zulaessige Korridor begrenzt die Adminkonfiguration; technisch kann
    | die Anwendung auch andere ausdruecklich freigegebene Preise verarbeiten.
    |
    */
    'pricing' => [
        'per_statement_gross_cent' => (int) env('PRICE_PER_STATEMENT_GROSS_CENT', 2490),
        'base_gross_cent' => (int) env('PRICE_BASE_GROSS_CENT', 0),
        'vat_rate_percent' => (int) env('VAT_RATE_PERCENT', 19),
        'currency' => env('STRIPE_CURRENCY', 'eur'),
        'admin_range_gross_cent' => [
            'min' => 2000,
            'max' => 3000,
        ],

        /*
         * Frist in Tagen, innerhalb der eine Korrektur nach der Zahlung
         * kostenfrei ist. 0 bedeutet: keine kostenfreie Korrektur. Der Wert ist
         * eine kaufmaennische Entscheidung des Betreibers und vor Livegang
         * festzulegen. Ein erneuter Betrag wird niemals ohne transparente
         * Anzeige und Bestaetigung erhoben.
         */
        'correction_free_days' => (int) env('PRICE_CORRECTION_FREE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rechnungsnummernkreis
    |--------------------------------------------------------------------------
    |
    | Lueckenlose, atomar vergebene Rechnungsnummer, Beispiel NK-2026-000001.
    | Die Vergabe erfolgt in einer Transaktion mit Zeilensperre.
    |
    */
    'invoicing' => [
        'number_prefix' => env('INVOICE_NUMBER_PREFIX', 'NK'),
        'number_padding' => (int) env('INVOICE_NUMBER_PADDING', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'max_file_mb' => (int) env('UPLOAD_MAX_FILE_MB', 25),
        'max_run_mb' => (int) env('UPLOAD_MAX_RUN_MB', 250),
        'chunk_size_mb' => (int) env('UPLOAD_CHUNK_SIZE_MB', 4),
        'accepted_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/heic',
            'image/heif',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'malware_scanner' => [
            // clamav | external | disabled
            'driver' => env('MALWARE_SCANNER_DRIVER', 'disabled'),
            'endpoint' => env('MALWARE_SCANNER_ENDPOINT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung und Loeschung
    |--------------------------------------------------------------------------
    |
    | Die Kurzzeit-TTL beginnt mit Eingang des ersten Upload-Chunks und darf
    | 120 Minuten nicht ueberschreiten. Der Wert wird hart begrenzt, damit eine
    | fehlerhafte Konfiguration das Datenschutzkonzept nicht aufweicht.
    |
    | Leere Aufbewahrungsfristen bedeuten: unbefristet bis zur dokumentierten
    | Betreiberentscheidung. Sie sind vor Livegang festzulegen.
    |
    */
    'retention' => [
        'temp_upload_ttl_minutes' => min(120, max(1, (int) env('TEMP_UPLOAD_TTL_MINUTES', 120))),
        'temp_upload_ttl_hard_limit_minutes' => 120,
        'temp_cleanup_interval_minutes' => (int) env('TEMP_CLEANUP_INTERVAL_MINUTES', 10),
        'ai_provider_file_ttl_minutes' => (int) env('AI_PROVIDER_FILE_TTL_MINUTES', 30),
        'extracted_data_days' => env('EXTRACTED_DATA_RETENTION_DAYS') !== null
            ? (int) env('EXTRACTED_DATA_RETENTION_DAYS')
            : null,
        'generated_pdf_days' => env('GENERATED_PDF_RETENTION_DAYS') !== null
            ? (int) env('GENERATED_PDF_RETENTION_DAYS')
            : null,
        'signed_download_ttl_minutes' => (int) env('SIGNED_DOWNLOAD_TTL_MINUTES', 30),

        /*
         * Frist zwischen Loeschantrag und endgueltiger Loeschung eines Kontos.
         * Innerhalb der Frist kann der Nutzer den Antrag zuruecknehmen. Der im
         * Antrag protokollierte Termin hat Vorrang, damit eine spaetere
         * Konfigurationsaenderung einen laufenden Antrag nicht verschiebt.
         * Korridor 7 bis 90 Tage.
         */
        'account_deletion_grace_days' => min(90, max(7, (int) env('ACCOUNT_DELETION_GRACE_DAYS', 30))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Erinnerungen fuer Folgejahre
    |--------------------------------------------------------------------------
    |
    | Format MM-TT in der Zeitzone Europe/Berlin. Die Quartalstermine sind im
    | jeweiligen Quartal konfigurierbar, der Dezembertermin ist vorbelegt.
    |
    */
    'reminders' => [
        'enabled' => (bool) env('REMINDER_ENABLED', true),
        'q1' => env('REMINDER_Q1_DATE', '01-15'),
        'q2' => env('REMINDER_Q2_DATE', '04-15'),
        'q3' => env('REMINDER_Q3_DATE', '07-15'),
        'december' => env('REMINDER_DECEMBER_DATE', '12-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vorschau und PDF
    |--------------------------------------------------------------------------
    */
    'pdf' => [
        'watermark_text' => 'VORSCHAU – NICHT BEZAHLT – NICHT ZUR VERWENDUNG',
        'watermark_footer' => 'Unbezahlte Vorschau',
        'tenant_footer' => 'Erstellt über smart-abrechnen.de',
        'template_version' => '1.0.0',
        'body_font_pt' => 10.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Toleranzen der Pruefregeln
    |--------------------------------------------------------------------------
    |
    | Werte in Cent beziehungsweise Prozent. Abweichungen oberhalb der Toleranz
    | blockieren die automatische Finalisierung, bis der Nutzer sie erklaert
    | oder korrigiert.
    |
    */
    'tolerances' => [
        'checksum_cent' => 100,
        'prior_year_deviation_percent' => 30,
        'billing_period_months_limit' => 12,

        /*
         * Aufmerksamkeitsschwelle fuer eine einzelne Kostenposition. Oberhalb
         * dieses Betrages erzeugt die Regel-Engine einen Hinweis, damit ein
         * ungewoehnlich hoher Einzelbetrag nicht unbemerkt in die Umlage laeuft.
         * Es ist keine Ablehnung, sondern eine Bitte um Pruefung. Der Wert ist
         * eine Erfahrungsannahme und im Betrieb nachzujustieren.
         */
        'single_amount_attention_cent' => (int) env('RULE_SINGLE_AMOUNT_ATTENTION_CENT', 200000),
    ],

];
