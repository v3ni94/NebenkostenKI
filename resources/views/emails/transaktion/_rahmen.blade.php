{{--
    Gemeinsamer Rahmen aller HTML-Mails (Transaktionsmails aus app/Mail und
    Kontonachrichten aus app/Notifications, deren Rahmen emails/auth/_rahmen
    hierher delegiert).

    Gestaltung nach docs/designsystem.md ("Editorial Klarheit") in der fuer
    Mailprogramme noetigen Uebersetzung: Tabellenlayout, Inline-Stile, maximale
    Breite 600 px, Systemschrift. Leinwand Canvas (#FAF8F4), weisse Karte mit
    hauchduenner Linie (#E6E3DD), HVM-Kennlinie als Kartenkante, Wortmarke statt
    Logo (ein Bild waere nur als externe Ressource oder Base64 moeglich, beides
    ist untersagt), Orange ausschliesslich fuer den Akzentstrich und die eine
    Schaltflaeche, Fusszeile als Graphit-Flaeche (#141414) mit Hellgrau-Text
    und der Kennlinie als unterem Abschluss.

    Farben: Fliesstext Textschwarz #1A1A1A, Sekundaertext #5C5C5E (AA auf
    Weiss und Canvas), auf Graphit Hellgrau #D7D8DA und Weiss. Anthrazit
    #87888A erscheint nur als Segment der Kennlinie, nie als Text.

    Keine Werbung, keine Superlative, kein Tracking, kein externes Bild, kein
    Skript. Die eingebettete Stilangabe im Kopf dient nur der Anpassung der
    Innenabstaende unter 620 px Breite; alle Farben und Groessen stehen inline.

    Erwartete Variablen:
      $titel       Ueberschrift der Nachricht
      $inhalt      Absaetze als Liste von Zeichenketten
      $aktionText  Beschriftung der Schaltflaeche, optional
      $aktionUrl   Ziel der Schaltflaeche, optional
      $punkte      Aufzaehlung als Liste von Zeichenketten, optional
      $fussnoten   weitere Hinweise als Liste von Zeichenketten, optional
      $abmeldeUrl  Abmeldelink der Erinnerungen, optional
--}}
@php
    $betreiber = config('smartabrechnen.operator');
    $marke = config('smartabrechnen.brand');
    $punkte = $punkte ?? [];
    $fussnoten = $fussnoten ?? [];
    $aktionText = $aktionText ?? null;
    $aktionUrl = $aktionUrl ?? null;
    $abmeldeUrl = $abmeldeUrl ?? null;
    $schrift = "font-family:system-ui, -apple-system, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $titel }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .sa-innen { padding-left: 22px !important; padding-right: 22px !important; }
            .sa-kopf { padding-top: 24px !important; }
            .sa-titel { font-size: 23px !important; }
            .sa-aussen { padding: 16px 10px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#faf8f4; -webkit-text-size-adjust:100%;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#faf8f4;">
        <tr>
            <td align="center" class="sa-aussen" style="padding:32px 12px;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid #e6e3dd; border-radius:24px; border-collapse:separate; overflow:hidden;">

                    {{-- HVM-Kennlinie als obere Kartenkante: Anthrazit, Mittelgrau, Orange, Hellgrau --}}
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="40%" height="3" style="background-color:#87888a; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="20%" height="3" style="background-color:#9c9d9f; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="7%" height="3" style="background-color:#e6a83c; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="33%" height="3" style="background-color:#d7d8da; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Wortmarke und Markenzusatz --}}
                    <tr>
                        <td class="sa-innen sa-kopf" style="padding:28px 40px 0 40px; {{ $schrift }}">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:0 0 22px 0; border-bottom:1px solid #e6e3dd; {{ $schrift }}">
                                        <p style="margin:0; font-size:18px; line-height:1.3; font-weight:600; letter-spacing:-0.01em; color:#1a1a1a;">
                                            {{ $marke['name'] }}
                                        </p>
                                        <p style="margin:3px 0 0 0; font-size:12px; line-height:1.5; color:#5c5c5e;">
                                            {{ $marke['relation_short'] }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Akzentstrich und Ueberschrift --}}
                    <tr>
                        <td class="sa-innen" style="padding:28px 40px 0 40px; {{ $schrift }}">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="32" height="2" style="width:32px; background-color:#e6a83c; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                            <h1 class="sa-titel" style="margin:14px 0 0 0; font-size:26px; line-height:1.2; font-weight:600; letter-spacing:-0.01em; color:#1a1a1a; overflow-wrap:anywhere;">
                                {{ $titel }}
                            </h1>
                        </td>
                    </tr>

                    {{-- Absaetze --}}
                    <tr>
                        <td class="sa-innen" style="padding:20px 40px 0 40px; {{ $schrift }} font-size:16px; line-height:1.6; color:#1a1a1a; overflow-wrap:break-word;">
                            @foreach ($inhalt as $absatz)
                                <p style="margin:0 0 14px 0;">{{ $absatz }}</p>
                            @endforeach
                        </td>
                    </tr>

                    {{-- Aufzaehlung als Hervorhebung auf Canvas --}}
                    @if ($punkte !== [])
                        <tr>
                            <td class="sa-innen" style="padding:2px 40px 8px 40px; {{ $schrift }}">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                       style="background-color:#faf8f4; border-radius:16px; border-collapse:separate;">
                                    <tr>
                                        <td style="padding:16px 20px; {{ $schrift }} font-size:16px; line-height:1.6; color:#1a1a1a;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                @foreach ($punkte as $punkt)
                                                    <tr>
                                                        <td width="18" valign="top" style="width:18px; padding:0 0 {{ $loop->last ? '0' : '6px' }} 0; {{ $schrift }} font-size:16px; line-height:1.6; color:#5c5c5e;">&bull;</td>
                                                        <td valign="top" style="padding:0 0 {{ $loop->last ? '0' : '6px' }} 0; {{ $schrift }} font-size:16px; line-height:1.6; color:#1a1a1a; overflow-wrap:break-word;">{{ $punkt }}</td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- Die eine Schaltflaeche in Orange, Pillform, 48 px hoch --}}
                    @if ($aktionText !== null && $aktionUrl !== null)
                        <tr>
                            <td class="sa-innen" style="padding:14px 40px 0 40px; {{ $schrift }}">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
                                    <tr>
                                        <td align="center" style="background-color:#e6a83c; border-radius:9999px; mso-padding-alt:0;">
                                            <a href="{{ $aktionUrl }}"
                                               style="display:inline-block; padding:14px 28px; {{ $schrift }} font-size:16px; line-height:20px; font-weight:600; color:#1a1a1a; text-decoration:none; border-radius:9999px;">
                                                {{ $aktionText }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td class="sa-innen" style="padding:20px 40px 0 40px; {{ $schrift }} font-size:13px; line-height:1.6; color:#5c5c5e;">
                                <p style="margin:0 0 4px 0;">
                                    Falls die Schaltfläche nicht funktioniert, kopieren Sie bitte die folgende Adresse in
                                    die Adresszeile Ihres Browsers:
                                </p>
                                <p style="margin:0; word-break:break-all; overflow-wrap:anywhere;">
                                    <a href="{{ $aktionUrl }}" style="color:#1a1a1a; text-decoration:underline; text-decoration-color:#e6a83c; text-underline-offset:3px;">{{ $aktionUrl }}</a>
                                </p>
                            </td>
                        </tr>
                    @endif

                    {{-- Hinweise --}}
                    @if ($fussnoten !== [])
                        <tr>
                            <td class="sa-innen" style="padding:24px 40px 0 40px; {{ $schrift }}">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="padding:18px 0 0 0; border-top:1px solid #e6e3dd; {{ $schrift }} font-size:14px; line-height:1.6; color:#5c5c5e; overflow-wrap:anywhere;">
                                            @foreach ($fussnoten as $fussnote)
                                                <p style="margin:0 0 {{ $loop->last ? '0' : '8px' }} 0;">{{ $fussnote }}</p>
                                            @endforeach
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- Grussformel --}}
                    <tr>
                        <td class="sa-innen" style="padding:28px 40px 32px 40px; {{ $schrift }}">
                            <p style="margin:0 0 2px 0; font-size:16px; line-height:1.6; color:#1a1a1a;">Mit freundlichen Grüßen</p>
                            <p style="margin:0; font-size:16px; line-height:1.6; font-weight:600; color:#1a1a1a;">Ihr Team von Smart Abrechnen</p>
                            <p style="margin:2px 0 0 0; font-size:14px; line-height:1.6; color:#5c5c5e;">{{ $betreiber['legal_name'] }}</p>
                        </td>
                    </tr>

                    {{-- Fusszeile auf Graphit: Betreiberangaben, Abmeldung, Hinweis --}}
                    <tr>
                        <td class="sa-innen" style="padding:28px 40px 28px 40px; background-color:#141414; {{ $schrift }} font-size:13px; line-height:1.6; color:#d7d8da;">
                            <p style="margin:0 0 12px 0; font-size:13px; line-height:1.6; color:#ffffff;">
                                {{ $marke['relation'] }}
                            </p>
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#d7d8da;">
                                {{ $betreiber['legal_name'] }}<br>
                                {{ $betreiber['address_line'] }}, {{ $betreiber['postal_code'] }} {{ $betreiber['city'] }}<br>
                                {{ $betreiber['register_court'] }}, {{ $betreiber['register_number'] }}<br>
                                Geschäftsführer: {{ $betreiber['managing_director'] }}
                            </p>

                            @if ($abmeldeUrl !== null)
                                <p style="margin:16px 0 0 0; padding:16px 0 0 0; border-top:1px solid #262626; font-size:13px; line-height:1.6; color:#d7d8da;">
                                    Sie erhalten diese Erinnerung, weil für Ihr Objekt noch eine Abrechnung offen ist.
                                    Erinnerungen abmelden:<br>
                                    <a href="{{ $abmeldeUrl }}" style="color:#ffffff; text-decoration:underline; text-decoration-color:#9c9d9f; text-underline-offset:3px; word-break:break-all; overflow-wrap:anywhere;">{{ $abmeldeUrl }}</a>
                                </p>
                                <p style="margin:8px 0 0 0; font-size:13px; line-height:1.6; color:#d7d8da;">
                                    Nachrichten zu Konto, Zahlung und Rechnung erhalten Sie unabhängig davon weiterhin.
                                </p>
                            @endif

                            <p style="margin:16px 0 0 0; padding:16px 0 0 0; border-top:1px solid #262626; font-size:13px; line-height:1.6; color:#d7d8da;">
                                Diese Nachricht wurde automatisch erstellt. Bitte senden Sie keine Unterlagen per E-Mail.
                            </p>
                        </td>
                    </tr>

                    {{-- HVM-Kennlinie als unterer Abschluss der Graphit-Flaeche --}}
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="40%" height="3" style="background-color:#87888a; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="20%" height="3" style="background-color:#9c9d9f; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="7%" height="3" style="background-color:#e6a83c; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="33%" height="3" style="background-color:#d7d8da; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
