@php
    $betreiber = config('smartabrechnen.operator');
@endphp
SMART ABRECHNEN
Bitte bestätigen Sie Ihre E-Mail-Adresse

{{ $anrede }}

Sie haben ein Konto bei Smart Abrechnen angelegt. Bitte bestätigen Sie Ihre
E-Mail-Adresse. Erst danach können Sie eine Abrechnung bezahlen und die
fertigen Unterlagen herunterladen.

Bestätigungslink:
{{ $url }}

Der Link ist aus Sicherheitsgründen {{ $gueltigkeitMinuten }} Minuten gültig. Danach können Sie
im Portal einen neuen Link anfordern.

Haben Sie kein Konto angelegt, ignorieren Sie diese Nachricht bitte. Es
entstehen Ihnen keine Kosten.

Mit freundlichen Grüßen
Ihr Team von Smart Abrechnen

--
{{ $betreiber['legal_name'] }}
{{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}
{{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}
Geschäftsführer: {{ $betreiber['managing_director'] }}

Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen
per E-Mail.
