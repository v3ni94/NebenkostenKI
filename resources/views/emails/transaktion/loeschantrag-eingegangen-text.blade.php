@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihr Löschantrag ist eingegangen',
    'inhalt' => [
        $anrede,
        'für Ihr Konto bei Smart Abrechnen ist ein Antrag auf Löschung eingegangen. Die endgültige '
            .'Löschung erfolgt am '.$faelligAm.'. Bis dahin bleibt Ihr Konto uneingeschränkt nutzbar.',
        'Sie können den Antrag innerhalb der Frist von '.$fristTage.' Tagen jederzeit zurücknehmen. '
            .'Melden Sie sich dazu an und öffnen Sie den Bereich Datenschutz und Löschung.',
        'Haben Sie diesen Antrag nicht gestellt, nehmen Sie ihn bitte umgehend zurück und ändern Sie '
            .'Ihr Passwort.',
    ],
    'aktionText' => 'Löschantrag ansehen',
    'aktionUrl' => $datenschutzUrl,
    'fussnoten' => [
        'Die Rechnungen der Hausverwaltung Müller GmbH bleiben aus Aufbewahrungsgründen erhalten. Alle '
            .'übrigen Daten werden nach Ablauf der Frist endgültig gelöscht.',
        'Diese Nachricht erhalten Sie unabhängig von Ihren Erinnerungseinstellungen, weil sie Ihr Konto betrifft.',
    ],
])
