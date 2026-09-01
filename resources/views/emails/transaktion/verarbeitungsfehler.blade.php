@include('emails.transaktion._rahmen', [
    'titel' => 'Ihre Abrechnung benötigt Ihre Mithilfe',
    'inhalt' => [
        $anrede,
        'bei der Abrechnung für das Objekt '.$objekt.' und das Abrechnungsjahr '.$jahr
            .' ist ein Punkt aufgetreten, den wir nicht allein lösen können.',
        'Was ist passiert: '.$sachverhalt,
        'Was Sie jetzt tun können: '.$empfehlung,
    ],
    'aktionText' => 'Abrechnung öffnen',
    'aktionUrl' => $portalUrl,
    'fussnoten' => [
        'Es sind keine Kosten entstanden. Ihr Stand bleibt gespeichert.',
        'Kommen Sie nicht weiter, antworten Sie bitte nicht auf diese Nachricht, sondern nutzen Sie '
            .'das Kontaktformular im Portal.',
    ],
])
