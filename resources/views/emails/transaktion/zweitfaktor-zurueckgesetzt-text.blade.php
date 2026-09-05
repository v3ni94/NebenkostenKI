@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihr zweiter Faktor wurde zurückgesetzt',
    'inhalt' => [
        $anrede,
        'auf Ihre Anfrage hat der Betreiber von Smart Abrechnen die Zwei-Faktor-Authentifizierung Ihres '
            .'Kontos zurückgesetzt. Alle offenen Sitzungen wurden beendet.',
        'Sie können sich jetzt mit E-Mail-Adresse und Passwort anmelden. Bitte richten Sie den zweiten '
            .'Faktor anschließend in Ihrem Konto neu ein.',
        'Haben Sie diese Zurücksetzung nicht veranlasst, wenden Sie sich bitte umgehend an '
            .'kontakt@smart-abrechnen.de und ändern Sie Ihr Passwort.',
    ],
    'aktionText' => 'Zweiten Faktor neu einrichten',
    'aktionUrl' => $einrichtungUrl,
    'fussnoten' => [
        'Diese Nachricht erhalten Sie unabhängig von Ihren Erinnerungseinstellungen, weil sie Ihr Konto betrifft.',
    ],
])
