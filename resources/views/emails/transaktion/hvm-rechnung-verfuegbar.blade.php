@include('emails.transaktion._rahmen', [
    'titel' => 'Ihre Rechnung steht bereit',
    'inhalt' => [
        $anrede,
        'zu Ihrer Zahlung liegt die Rechnung '.$rechnungsnummer.' vom '.$ausgestelltAm
            .' über '.$brutto.' vor.',
        'Die Rechnung ist die Leistungsrechnung der Hausverwaltung Müller GmbH für die Erstellung '
            .'Ihrer Abrechnungen. Sie ist nicht die Betriebskostenabrechnung Ihrer Mieter.',
    ],
    'aktionText' => 'Rechnung im Portal ansehen',
    'aktionUrl' => $portalUrl,
    'fussnoten' => $rechnungAngehaengt
        ? ['Die Rechnung ist dieser Nachricht als PDF beigefügt.']
        : ['Die Rechnung finden Sie in Ihrem Konto zum Abruf.'],
])
