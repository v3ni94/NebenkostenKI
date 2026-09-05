@include('emails.transaktion._rahmen', [
    'titel' => 'Es sind Prüfaufgaben offen',
    'inhalt' => [
        $anrede,
        'für das Objekt '.$objekt.' und das Abrechnungsjahr '.$jahr.' sind '.$offen
            .' Punkte offen. Ohne Ihre Bestätigung wird die Abrechnung nicht fortgeführt.',
        'Fehlende Werte werden von uns nicht geschätzt. Wir fragen sie ausdrücklich bei Ihnen ab.',
    ],
    'punkte' => $themen,
    'aktionText' => 'Offene Punkte bearbeiten',
    'aktionUrl' => $portalUrl,
    'fussnoten' => [
        'Sie können den Vorgang jederzeit unterbrechen. Ihr Stand bleibt gespeichert.',
    ],
])
