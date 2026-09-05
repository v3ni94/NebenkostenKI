@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihre Zahlung ist eingegangen',
    'inhalt' => [
        $anrede,
        'wir haben Ihre Zahlung über '.$betrag.' am '.$bezahltAm.' erhalten. Sie betrifft das Objekt '
            .$objekt.' und das Abrechnungsjahr '.$jahr.' mit '.$abrechnungen.' Mieterabrechnungen.',
        'Die endgültigen Abrechnungen werden jetzt ohne Wasserzeichen erstellt. Sie erhalten eine '
            .'weitere Nachricht, sobald die Unterlagen bereitstehen.',
    ],
    'aktionText' => 'Stand im Portal ansehen',
    'aktionUrl' => $portalUrl,
    'fussnoten' => $rechnungAngehaengt
        ? ['Ihre Rechnung ist dieser Nachricht als PDF beigefügt.']
        : ['Ihre Rechnung finden Sie in Ihrem Konto zum Abruf.'],
])
