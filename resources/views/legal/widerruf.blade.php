{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Platzhalterseite. Es wird kein Widerrufstext und kein Musterformular
    formuliert. Angelegt ist ausschliesslich die Gliederung mit Platzhaltern
    in eckigen Klammern. Der Text zur sofortigen Vertragsausfuehrung und zum
    moeglichen Erloeschen des Widerrufsrechts ist ebenfalls anwaltlich
    freizugeben.
--}}
@extends('layouts.legal')

@section('meta_title', 'Widerrufsbelehrung')
@section('meta_description', 'Widerrufsbelehrung von smart-abrechnen.de. Die Seite ist eine strukturierte Platzhalterfassung und wird vor dem Livegang anwaltlich geprüft und freigegeben.')

@section('legal_title', 'Widerrufs&shy;belehrung')
@section('legal_intro', 'Diese Seite enthält die Gliederung der Widerrufsbelehrung für Verbraucher. Die Textfassung wird vor dem Livegang anwaltlich erstellt und freigegeben.')

@section('legal_content')
    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">1. Anwendungsbereich</h2>
        <p class="mt-3">[Abgrenzung, für welche Nutzergruppen ein Widerrufsrecht besteht]</p>
        <p class="mt-2">[Hinweis, dass das kostenlose Konto und Entwürfe kein entgeltlicher Vertrag sind]</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">2. Widerrufsrecht</h2>
        <p class="mt-3">[Belehrung über das Widerrufsrecht, Frist und Beginn der Frist]</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">3. Ausübung des Widerrufs</h2>
        <p class="mt-3">[Form der Erklärung und Empfängeranschrift einschließlich E-Mail-Adresse]</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">4. Folgen des Widerrufs</h2>
        <p class="mt-3">[Rückzahlungspflicht, Frist und Zahlungsweg]</p>
        <p class="mt-2">[Regelung zu einem Wertersatz für bereits erbrachte Leistungen]</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">5. Vorzeitiges Erlöschen des Widerrufsrechts</h2>
        <p class="mt-3">[Belehrung zum vorzeitigen Erlöschen bei sofortiger Vertragsausführung digitaler Leistungen]</p>
        <p class="mt-2">[Wortlaut der gesonderten Bestätigung, die im Bezahlvorgang eingeholt wird]</p>

        <div class="mt-5">
            <x-hvm.alert variant="warning" title="Umsetzung im Bezahlvorgang">
                Die Bestätigung wird als gesonderte, nicht vorangekreuzte Auswahl eingeholt. Der Wortlaut ist ein
                Platzhalter und wird vor dem Livegang anwaltlich freigegeben. Zeitpunkt, Textversion und Zustimmung
                werden protokolliert.
            </x-hvm.alert>
        </div>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">6. Muster-Widerrufsformular</h2>
        <p class="mt-3">[Muster-Widerrufsformular in der gesetzlich vorgegebenen Fassung]</p>
    </section>
@endsection
