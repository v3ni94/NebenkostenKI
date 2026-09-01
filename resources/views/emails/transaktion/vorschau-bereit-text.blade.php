@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihre Vorschau ist bereit',
    'inhalt' => [
        $anrede,
        'für das Objekt '.$objekt.' und das Abrechnungsjahr '.$jahr.' liegt die Vorschau bereit. '
            .'Sie umfasst '.$abrechnungen.' Mieterabrechnungen.',
        'Jede Vorschauseite trägt ein Wasserzeichen und ist nicht zur Verwendung gegenüber Mietern '
            .'bestimmt. Der Preis für die Freischaltung beträgt '.$preis.'.',
    ],
    'aktionText' => 'Vorschau öffnen',
    'aktionUrl' => $portalUrl,
    'fussnoten' => [
        'Die Vorschau wird bewusst nicht an diese Nachricht angehängt. Sie rufen sie in Ihrem Konto ab.',
    ],
])
