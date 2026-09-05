@php
    $betreiber = config('smartabrechnen.operator');
@endphp
SMART ABRECHNEN
Zu Ihrer E-Mail-Adresse besteht bereits ein Konto

{{ $anrede }}

zu Ihrer E-Mail-Adresse wurde eben eine Registrierung bei Smart Abrechnen
versucht. Unter dieser Adresse besteht bereits ein Konto, deshalb haben wir
kein zweites Konto angelegt.

Wenn Sie den Versuch selbst unternommen haben, melden Sie sich bitte einfach
mit Ihrem bestehenden Konto an. Haben Sie Ihr Passwort vergessen, setzen Sie es
über den zweiten Link zurück.

Anmeldung:
{{ $anmeldenUrl }}

Passwort zurücksetzen:
{{ $passwortUrl }}

Waren Sie es nicht, ist nichts zu tun. Ihr Konto und Ihr Passwort sind
unverändert. Es wurde durch diesen Versuch kein Zugang gewährt.

Mit freundlichen Grüßen
Ihr Team von Smart Abrechnen
{{ $betreiber['legal_name'] }}

--
{{ config('smartabrechnen.brand.relation') }}
{{ $betreiber['legal_name'] }}
{{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}
{{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}
Geschäftsführer: {{ $betreiber['managing_director'] }}

Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen
per E-Mail.
