@include('emails.auth._rahmen', [
    'titel' => 'Passwort zurücksetzen',
    'inhalt' => [
        $anrede,
        'Für Ihr Konto bei Smart Abrechnen wurde ein neues Passwort angefordert. '
            .'Über die folgende Schaltfläche vergeben Sie ein neues Passwort.',
    ],
    'aktionText' => 'Neues Passwort vergeben',
    'aktionUrl' => $url,
    'fussnoten' => [
        'Der Link ist '.$gueltigkeitMinuten.' Minuten gültig und lässt sich nur einmal verwenden.',
        'Haben Sie kein neues Passwort angefordert, ist nichts weiter zu tun. '
            .'Ihr bisheriges Passwort bleibt gültig.',
    ],
])
