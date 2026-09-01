@include('emails.transaktion._rahmen', [
    'titel' => $istDezember ? 'Frist zum Jahresende' : 'Erinnerung an Ihre Abrechnung',
    'inhalt' => [
        $anrede,
        'für das Objekt '.$objekt.' liegt für das Abrechnungsjahr '.$jahr
            .' noch keine abgeschlossene Betriebskostenabrechnung vor.',
        $istDezember
            ? 'Die gesetzliche Abrechnungsfrist endet regelmäßig zum Ende dieses Jahres. Bitte prüfen '
                .'Sie Ihren Einzelfall und starten Sie die Abrechnung jetzt. Diese Angabe ist ein '
                .'Hinweis und keine Rechtsberatung.'
            : 'Wir haben den Abrechnungslauf für Sie vorbereitet. Objektdaten, Einheiten, laufende '
                .'Mietverhältnisse und Verteilerschlüssel sind aus dem Vorjahr übernommen.',
        'Neue Belege, Mieterwechsel, Vorauszahlungen, Zählerstände und Heizkosten bestätigen Sie für '
            .'das neue Jahr erneut.',
    ],
    'aktionText' => 'Abrechnung '.$jahr.' starten',
    'aktionUrl' => $startUrl,
    'abmeldeUrl' => $abmeldeUrl,
])
