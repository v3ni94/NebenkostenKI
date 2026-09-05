@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihre Unterlagen sind ausgewertet',
    'inhalt' => [
        $anrede,
        'die automatische Auswertung Ihrer Unterlagen für das Objekt '.$objekt.' und das Abrechnungsjahr '
            .$jahr.' ist abgeschlossen. Ausgewertet wurden '.$dokumente.' Dokumente.',
        'Im Portal finden Sie jetzt die ausgelesenen Kostenwerte mit Angabe der Fundstelle. '
            .'Bitte prüfen Sie die Werte und bestätigen Sie die offenen Punkte.',
        'Mietverträge, Vorjahresabrechnungen, Zahlungsübersichten, Mieter- und Zählerlisten werden abgelegt und '
            .'Ihnen zur Sichtprüfung angezeigt. Vorauszahlungen, Mietzeiten, Umlagevereinbarungen und Zählerstände '
            .'erfassen Sie in den folgenden Schritten selbst.',
    ],
    'aktionText' => 'Auswertung ansehen',
    'aktionUrl' => $portalUrl,
    'fussnoten' => [
        'Ihre Originaldateien wurden nach der Auswertung gelöscht. Bitte bewahren Sie Rechnungen, '
            .'Bescheide und Mietverträge selbst auf und halten Sie sie für eine mögliche Belegeinsicht bereit.',
    ],
])
