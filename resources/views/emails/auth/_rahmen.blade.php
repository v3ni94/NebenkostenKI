{{--
    Rahmen der Kontonachrichten aus app/Notifications (Verifizierung, Passwort,
    Konto bereits vorhanden).

    Die Gestaltung ist mit den Transaktionsmails identisch und liegt zentral in
    emails/transaktion/_rahmen. Diese Datei reicht die Variablen durch, damit
    beide Mailfamilien denselben Rahmen im CI verwenden und Aenderungen nur an
    einer Stelle noetig sind.

    Erwartete Variablen:
      $titel          Ueberschrift der Nachricht
      $inhalt         Absaetze als Liste von Zeichenketten
      $aktionText     Beschriftung der Schaltflaeche, optional
      $aktionUrl      Ziel der Schaltflaeche, optional
      $fussnoten      weitere Hinweise als Liste von Zeichenketten, optional
--}}
@include('emails.transaktion._rahmen', [
    'titel' => $titel,
    'inhalt' => $inhalt,
    'aktionText' => $aktionText ?? null,
    'aktionUrl' => $aktionUrl ?? null,
    'fussnoten' => $fussnoten ?? [],
    'punkte' => [],
    'abmeldeUrl' => null,
])
