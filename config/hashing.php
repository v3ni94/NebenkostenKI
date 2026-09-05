<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Passworthashing
|------------------------------------------------------------------------------
|
| Vorgabe des Masterprompts, Abschnitt 8.1: Argon2id. Die PHP-Installation des
| Zielhosts muss dafuer mit Argon2-Unterstuetzung gebaut sein. Geprueft wird das
| mit:
|
|     php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'
|
| Auf der Entwicklungsumgebung dieses Arbeitspakets ist Argon2id vorhanden
| (PHP 8.4, password_algos() liefert 2y, argon2i, argon2id). Steht Argon2 auf
| dem IONOS-Zielhost nicht bereit, ist HASH_DRIVER=bcrypt in der .env zu setzen.
| Die Anwendung bleibt dann lauffaehig, weil Laravel bestehende Hashes am
| Algorithmuspraefix erkennt und beim naechsten Login neu hashen kann.
|
| KOSTENPARAMETER
|
| Die Werte unten sind eine konservative Startkonfiguration nach der OWASP-
| Empfehlung fuer Argon2id (mindestens 19 MiB Speicher, Parallelitaet 1). Sie
| sind AUF DEM ZIELHOST ZU MESSEN und so zu waehlen, dass ein einzelner Hash
| etwa 250 bis 500 Millisekunden benoetigt. Zu hohe Werte machen den Login zum
| Angriffsziel fuer eine Ressourcenerschoepfung, zu niedrige Werte schwaechen
| den Schutz gegen Offline-Angriffe.
|
| Messung auf dem Zielhost:
|
|     php -r '$t=microtime(true);
|             password_hash("test", PASSWORD_ARGON2ID,
|                 ["memory_cost"=>65536,"time_cost"=>4,"threads"=>1]);
|             echo round((microtime(true)-$t)*1000)," ms\n";'
|
| Auf der Entwicklungsumgebung dieses Arbeitspakets ergab die Messung mit den
| Standardwerten 314 Millisekunden. Der Wert ist auf dem Produktionshost erneut
| zu erheben und in der Livegang-Checkliste zu dokumentieren.
|
| Die Parameter sind ueber .env uebersteuerbar, damit die CI mit guenstigeren
| Werten laufen kann, ohne die Produktionskonfiguration zu veraendern.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Standardtreiber
    |--------------------------------------------------------------------------
    |
    | Unterstuetzt: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Argon2id
    |--------------------------------------------------------------------------
    |
    | memory  Speicherkosten in Kibibyte, 65536 entspricht 64 MiB
    | threads Parallelitaet, auf Shared Hosting bewusst 1
    | time    Anzahl der Durchlaeufe
    | verify  prueft zusaetzlich, ob der Hash mit dem erwarteten Algorithmus
    |         erzeugt wurde
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bcrypt
    |--------------------------------------------------------------------------
    |
    | Dokumentierte Rueckfalloption fuer den Fall, dass der Zielhost kein
    | Argon2 bereitstellt. Wird nur verwendet, wenn HASH_DRIVER=bcrypt gesetzt
    | ist.
    |
    */

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehashing bei Anmeldung
    |--------------------------------------------------------------------------
    |
    | Laravel hasht ein Passwort bei der Anmeldung neu, sobald die
    | Kostenparameter erhoeht werden. Damit wachsen bestehende Konten ohne
    | Zwangsruecksetzung in die staerkere Konfiguration hinein.
    |
    */

    'rehash_on_login' => true,

];
