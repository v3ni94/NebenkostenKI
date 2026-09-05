{{--
    Rueckleitung aus der gehosteten Zahlungsseite.

    VERBINDLICH: Diese Seite ist KEIN Zahlungsnachweis. Sie sagt ausdruecklich
    nicht, dass die Zahlung bestaetigt sei, solange die signaturgeprüfte
    Rueckmeldung des Anbieters nicht eingegangen ist (Abschnitt 15.1).

    Ist die Zahlung bestaetigt, die Erstellung der Abrechnungen aber gescheitert,
    sagt die Seite das ehrlich: Es ist nichts zu zahlen, der Betrieb holt die
    Erstellung nach.

    Gestaltung nach docs/designsystem.md: Seitenkopf (4.3), Meldung (4.14),
    Karte (4.5), ein Primaerbutton (4.12).
--}}
@extends('layouts.portal')

@section('titel', 'Zahlung wird bestätigt')

@section('content')
    <x-hvm.page-header
        eyebrow="Schritt 11 von 12"
        title="Wir bestätigen Ihre Zahlung"
        lead="Ihre Abrechnungen werden unmittelbar nach der Bestätigung des Zahlungsanbieters erstellt." />

    <div class="mt-10 max-w-3xl">
        @if ($verzoegert)
            <x-hvm.alert variant="warning" label="Hinweis" title="Zahlung bestätigt, Erstellung verzögert">
                Ihre Zahlung ist bestätigt. Bei der Erstellung Ihrer Abrechnungen ist eine technische Störung
                aufgetreten. Wir holen die Erstellung nach und melden uns per E-Mail, sobald Ihre Dateien
                bereitstehen. Sie müssen nichts weiter tun und nicht erneut zahlen.
            </x-hvm.alert>
        @elseif ($bezahlt)
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

        <x-hvm.card class="mt-6" title="Warum diese Seite noch keine Freigabe ist" eyebrow="Zur Einordnung">
            <div class="flex gap-4">
                <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                    <x-hvm.icon name="shield" class="h-5 w-5" />
                </span>
                <p class="min-w-0 max-w-prose text-base leading-relaxed text-hvm-textschwarz">
                    Die Rückleitung Ihres Browsers ist technisch kein Zahlungsnachweis. Ihre Abrechnungen werden erst
                    nach der signaturgeprüften Bestätigung des Zahlungsanbieters erzeugt und freigeschaltet. So ist
                    ausgeschlossen, dass eine nicht abgeschlossene Zahlung zu einer Freigabe führt.
                </p>
            </div>
        </x-hvm.card>

        <div class="mt-8 flex flex-wrap gap-3">
            <x-hvm.button href="{{ route('portal.checkout.erfolg', ['billingRun' => $lauf->getKey()]) }}"
                          variant="primary">
                Stand aktualisieren
            </x-hvm.button>
            <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                          variant="secondary">
                Zur Abrechnung
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        </div>
    </div>
@endsection
