@include('emails.auth._rahmen', [
    'titel' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse',
    'inhalt' => [
        $anrede,
        'Sie haben ein Konto bei Smart Abrechnen angelegt. Bitte bestätigen Sie Ihre E-Mail-Adresse. '
            .'Erst danach können Sie eine Abrechnung bezahlen und die fertigen Unterlagen herunterladen.',
    ],
    'aktionText' => 'E-Mail-Adresse bestätigen',
    'aktionUrl' => $url,
    'fussnoten' => [
        'Der Link ist aus Sicherheitsgründen '.$gueltigkeitMinuten.' Minuten gültig. '
            .'Danach können Sie im Portal einen neuen Link anfordern.',
        'Haben Sie kein Konto angelegt, ignorieren Sie diese Nachricht bitte. Es entstehen Ihnen keine Kosten.',
    ],
])
