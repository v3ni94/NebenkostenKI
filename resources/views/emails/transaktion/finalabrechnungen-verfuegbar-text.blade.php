@include('emails.transaktion._rahmen-text', [
    'titel' => 'Ihre Abrechnungen stehen bereit',
    'inhalt' => [
        $anrede,
        'für das Objekt '.$objekt.' und das Abrechnungsjahr '.$jahr.' stehen '.$abrechnungen
            .' endgültige Mieterabrechnungen ohne Wasserzeichen bereit.',
        'Aus Gründen des Datenschutzes versenden wir Mieterabrechnungen nicht als E-Mail-Anhang. '
            .'Bitte laden Sie die Unterlagen über den folgenden Link in Ihrem Konto herunter.',
    ],
    'aktionText' => 'Abrechnungen herunterladen',
    'aktionUrl' => $downloadUrl,
    'fussnoten' => [
        'Der Downloadlink ist '.$gueltigkeitMinuten.' Minuten gültig und an Ihr Konto gebunden. '
            .'Nach Ablauf melden Sie sich einfach im Portal an und rufen die Unterlagen dort erneut ab.',
        'Bitte prüfen Sie die Abrechnungen vor der Weitergabe an Ihre Mieter. Die inhaltliche '
            .'Verantwortung als Vermieter bleibt bei Ihnen.',
        'Ihr Konto erreichen Sie unter '.$portalUrl,
    ],
])
