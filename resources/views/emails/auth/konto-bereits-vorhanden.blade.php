@include('emails.auth._rahmen', [
    'titel' => 'Zu Ihrer E-Mail-Adresse besteht bereits ein Konto',
    'inhalt' => [
        $anrede,
        'zu Ihrer E-Mail-Adresse wurde eben eine Registrierung bei Smart Abrechnen versucht. Unter dieser '
            .'Adresse besteht bereits ein Konto, deshalb haben wir kein zweites Konto angelegt.',
        'Wenn Sie den Versuch selbst unternommen haben, melden Sie sich bitte einfach mit Ihrem bestehenden '
            .'Konto an. Haben Sie Ihr Passwort vergessen, setzen Sie es über den zweiten Link zurück.',
    ],
    'aktionText' => 'Jetzt anmelden',
    'aktionUrl' => $anmeldenUrl,
    'fussnoten' => [
        'Passwort zurücksetzen: '.$passwortUrl,
        'Waren Sie es nicht, ist nichts zu tun. Ihr Konto und Ihr Passwort sind unverändert. Es wurde durch '
            .'diesen Versuch kein Zugang gewährt.',
    ],
])
