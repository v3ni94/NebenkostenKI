{{--
    Gemeinsamer Rahmen der Transaktionsmails.

    Sachlicher HVM-Stil: Anthrazit fuer Ueberschrift und Fusszeile, Orange nur
    als schmale Kennlinie und fuer die eine wichtigste Schaltflaeche. Es wird
    KEIN Logo eingebunden, solange unter /public/ci kein freigegebenes Asset
    liegt. Ein Logo wird nicht erfunden und nicht nachgezeichnet
    (Masterprompt 18).

    Tabellenlayout und Inline-Stile sind in E-Mails bewusst gewaehlt, weil viele
    Mailprogramme externe Stylesheets und moderne Layoutverfahren nicht
    unterstuetzen.

    Erwartete Variablen:
      $titel          Ueberschrift der Nachricht
      $inhalt         Absaetze als Liste von Zeichenketten
      $aktionText     Beschriftung der Schaltflaeche, optional
      $aktionUrl      Ziel der Schaltflaeche, optional
      $fussnoten      weitere Hinweise als Liste von Zeichenketten, optional
--}}
@php
    $betreiber = config('smartabrechnen.operator');
    $fussnoten = $fussnoten ?? [];
    $aktionText = $aktionText ?? null;
    $aktionUrl = $aktionUrl ?? null;
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titel }}</title>
</head>
<body style="margin:0; padding:0; background-color:#ececec;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ececec;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                       style="width:600px; max-width:100%; background-color:#ffffff; border:1px solid #d7d8da;">
                    {{-- HVM-Kennlinie: Anthrazit, Mittelgrau, Orange, Hellgrau --}}
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="40%" height="4" style="background-color:#87888a; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="20%" height="4" style="background-color:#9c9d9f; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="7%" height="4" style="background-color:#e6a83c; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="33%" height="4" style="background-color:#d7d8da; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 32px 8px 32px; font-family:Arial, Helvetica, sans-serif;">
                            <p style="margin:0; font-size:13px; color:#87888a; letter-spacing:0.08em; text-transform:uppercase;">
                                Smart Abrechnen
                            </p>
                            <h1 style="margin:8px 0 0 0; font-size:20px; line-height:1.3; color:#87888a; font-weight:bold;">
                                {{ $titel }}
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 32px 0 32px; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#1a1a1a;">
                            @foreach ($inhalt as $absatz)
                                <p style="margin:0 0 14px 0;">{{ $absatz }}</p>
                            @endforeach
                        </td>
                    </tr>

                    @if ($aktionText !== null && $aktionUrl !== null)
                        <tr>
                            <td style="padding:10px 32px 6px 32px; font-family:Arial, Helvetica, sans-serif;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="background-color:#e6a83c; border:1px solid #c98f2b;">
                                            <a href="{{ $aktionUrl }}"
                                               style="display:inline-block; padding:12px 22px; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; color:#1a1a1a; text-decoration:none;">
                                                {{ $aktionText }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:8px 32px 0 32px; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.6; color:#1a1a1a;">
                                <p style="margin:0 0 6px 0;">
                                    Falls die Schaltfläche nicht funktioniert, kopieren Sie bitte die folgende Adresse in
                                    die Adresszeile Ihres Browsers:
                                </p>
                                <p style="margin:0; word-break:break-all; color:#87888a;">{{ $aktionUrl }}</p>
                            </td>
                        </tr>
                    @endif

                    @if ($fussnoten !== [])
                        <tr>
                            <td style="padding:18px 32px 0 32px; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.6; color:#1a1a1a;">
                                @foreach ($fussnoten as $fussnote)
                                    <p style="margin:0 0 8px 0;">{{ $fussnote }}</p>
                                @endforeach
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="padding:24px 32px 28px 32px; font-family:Arial, Helvetica, sans-serif;">
                            <p style="margin:0 0 4px 0; font-size:14px; color:#1a1a1a;">Mit freundlichen Grüßen</p>
                            <p style="margin:0; font-size:14px; color:#1a1a1a;">Ihr Team von Smart Abrechnen</p>

                            <hr style="border:0; border-top:1px solid #d7d8da; margin:20px 0 12px 0;">

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#87888a;">
                                {{ $betreiber['legal_name'] }}<br>
                                {{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}<br>
                                {{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}<br>
                                Geschäftsführer: {{ $betreiber['managing_director'] }}
                            </p>
                            <p style="margin:10px 0 0 0; font-size:12px; line-height:1.6; color:#87888a;">
                                Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen per E-Mail.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
