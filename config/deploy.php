<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Betrieb und Auslieferung
|------------------------------------------------------------------------------
|
| Schluessel, die ausschliesslich den Betrieb der Anwendung hinter dem
| IONOS-Proxy und die Inbetriebnahme betreffen. Keine Zugangsdaten.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Vertrauenswuerdige Proxys
    |--------------------------------------------------------------------------
    |
    | Hinter dem IONOS-Proxy beziehungsweise Load Balancer erreicht die
    | Anfrage PHP unverschluesselt und mit der Adresse des Proxys. Schema,
    | Host, Port und Client-IP stehen dann nur in den X-Forwarded-Headern.
    | Ohne Vertrauenskonfiguration
    |
    |   - leitet ForceHttps endlos auf https um, weil isSecure() falsch ist,
    |   - sieht die Ratenbegrenzung ueberall dieselbe Proxy-Adresse,
    |   - protokolliert das Audit-Log die Proxy-Adresse statt des Clients,
    |   - werden signierte URLs mit http statt https erzeugt und verworfen.
    |
    | Werte:
    |   leer        kein Proxy wird vertraut (Standard, lokale Entwicklung)
    |   *           allen Proxys vertrauen
    |   a.b.c.d,... kommagetrennte Adressen oder CIDR-Bereiche
    |
    | SICHERHEITSABWAEGUNG zu "*": IONOS veroeffentlicht die Adressen seiner
    | Proxys nicht, deshalb ist "*" auf IONOS Webhosting die praktikable
    | Einstellung. Das Risiko besteht darin, dass ein Client die
    | X-Forwarded-Header selbst setzt. Das ist nur dann moeglich, wenn er PHP
    | direkt und nicht ueber den Proxy erreicht. Auf IONOS Webhosting laeuft
    | jede Anfrage ueber die Plattform, die die Header des Clients ueberschreibt.
    | Auf einem eigenen Server (Profil B) sind stattdessen die konkreten
    | Adressen des vorgeschalteten Proxys einzutragen.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

];
