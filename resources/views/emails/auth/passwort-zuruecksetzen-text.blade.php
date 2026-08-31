@php
    $betreiber = config('smartabrechnen.operator');
@endphp
SMART ABRECHNEN
Passwort zurücksetzen

{{ $anrede }}

Für Ihr Konto bei Smart Abrechnen wurde ein neues Passwort angefordert. Über
den folgenden Link vergeben Sie ein neues Passwort.

Link zum Zurücksetzen:
{{ $url }}

Der Link ist {{ $gueltigkeitMinuten }} Minuten gültig und lässt sich nur einmal verwenden.

Haben Sie kein neues Passwort angefordert, ist nichts weiter zu tun. Ihr
bisheriges Passwort bleibt gültig.

Mit freundlichen Grüßen
Ihr Team von Smart Abrechnen

--
{{ $betreiber['legal_name'] }}
{{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}
{{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}
Geschäftsführer: {{ $betreiber['managing_director'] }}

Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen
per E-Mail.
