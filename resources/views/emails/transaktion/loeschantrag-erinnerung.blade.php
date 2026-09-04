@include('emails.transaktion._rahmen', [
    'titel' => 'Erinnerung: Ihr Konto wird in Kürze gelöscht',
    'inhalt' => [
        $anrede,
        'für Ihr Konto bei Smart Abrechnen liegt ein Löschantrag vor. Die endgültige Löschung erfolgt am '
            .$faelligAm.', also in '.$verbleibendeTage.' Tagen.',
        'Möchten Sie Ihr Konto behalten, nehmen Sie den Antrag bitte vor diesem Tag zurück. Melden Sie sich '
            .'dazu an und öffnen Sie den Bereich Datenschutz und Löschung. Nach Ablauf der Frist ist die '
            .'Löschung nicht mehr rückholbar.',
    ],
    'aktionText' => 'Löschantrag ansehen',
    'aktionUrl' => $datenschutzUrl,
    'fussnoten' => [
        'Diese Nachricht erhalten Sie unabhängig von Ihren Erinnerungseinstellungen, weil sie Ihr Konto betrifft.',
    ],
])
