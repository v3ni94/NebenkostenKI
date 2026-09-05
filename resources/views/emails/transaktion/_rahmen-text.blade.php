@php
    $betreiber = config('smartabrechnen.operator');
    $punkte = $punkte ?? [];
    $fussnoten = $fussnoten ?? [];
    $aktionText = $aktionText ?? null;
    $aktionUrl = $aktionUrl ?? null;
    $abmeldeUrl = $abmeldeUrl ?? null;
@endphp
SMART ABRECHNEN
{{ $titel }}

@foreach ($inhalt as $absatz)
{{ $absatz }}

@endforeach
@if ($punkte !== [])
@foreach ($punkte as $punkt)
* {{ $punkt }}
@endforeach

@endif
@if ($aktionText !== null && $aktionUrl !== null)
{{ $aktionText }}:
{{ $aktionUrl }}

@endif
@foreach ($fussnoten as $fussnote)
{{ $fussnote }}

@endforeach
Mit freundlichen Grüßen
Ihr Team von Smart Abrechnen
{{ $betreiber['legal_name'] }}

--
{{ config('smartabrechnen.brand.relation') }}
{{ $betreiber['legal_name'] }}
{{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}
{{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}
Geschäftsführer: {{ $betreiber['managing_director'] }}
@if ($abmeldeUrl !== null)

Sie erhalten diese Erinnerung, weil für Ihr Objekt noch eine Abrechnung offen
ist. Erinnerungen abmelden:
{{ $abmeldeUrl }}

Nachrichten zu Konto, Zahlung und Rechnung erhalten Sie unabhängig davon
weiterhin.
@endif

Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen
per E-Mail.
