{{--
    Rueckleitung aus der gehosteten Zahlungsseite.

    VERBINDLICH: Diese Seite ist KEIN Zahlungsnachweis. Sie sagt ausdruecklich
    nicht, dass die Zahlung bestaetigt sei, solange die signaturgeprüfte
    Rueckmeldung des Anbieters nicht eingegangen ist (Abschnitt 15.1).
--}}
@extends('layouts.portal')

@section('titel', 'Zahlung wird bestätigt')

@section('content')
    <x-hvm.section-heading
        eyebrow="Schritt 11 von 12"
        title="Wir bestätigen Ihre Zahlung"
        lead="Ihre Abrechnungen werden unmittelbar nach der Bestätigung des Zahlungsanbieters erstellt." />

    <div class="mt-8 max-w-3xl">
        @if ($bezahlt)
            <x-hvm.alert variant="success" label="Erledigt" title="Zahlung bestätigt">
                Die Zahlung ist bestätigt. Ihre Final-Abrechnungen werden erstellt. Sobald sie bereitstehen,
                erhalten Sie eine E-Mail mit einem sicheren Downloadlink.
            </x-hvm.alert>
        @else
            <x-hvm.alert variant="info" title="Bestätigung steht noch aus">
                Die Rückmeldung des Zahlungsanbieters liegt uns noch nicht vor. Das dauert in der Regel nur wenige
                Sekunden. Bitte laden Sie diese Seite in einem Moment erneut. Es ist nichts weiter zu tun, und Sie
                müssen nicht erneut zahlen.
            </x-hvm.alert>
        @endif

        <x-hvm.card class="mt-6" title="Warum diese Seite noch keine Freigabe ist">
            <p class="text-sm text-hvm-textschwarz">
                Die Rückleitung Ihres Browsers ist technisch kein Zahlungsnachweis. Ihre Abrechnungen werden erst
                nach der signaturgeprüften Bestätigung des Zahlungsanbieters erzeugt und freigeschaltet. So ist
                ausgeschlossen, dass eine nicht abgeschlossene Zahlung zu einer Freigabe führt.
            </p>
        </x-hvm.card>

        <div class="mt-8 flex flex-wrap gap-3">
            <x-hvm.button href="{{ route('portal.checkout.erfolg', ['billingRun' => $lauf->getKey()]) }}"
                          variant="primary">
                Stand aktualisieren
            </x-hvm.button>
            <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                          variant="secondary">
                Zur Abrechnung
            </x-hvm.button>
        </div>
    </div>
@endsection
