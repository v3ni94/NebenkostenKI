{{--
    Pflicht-Warnhinweis der Kostenprüfung.

    Der Hinweis erklärt allgemein, warum die betroffenen Positionen besonders
    zu prüfen sind. Er ist ausdrücklich keine Rechtsberatung im Einzelfall.
    Meldung des Designsystems (x-hvm.alert): Symbol, Statuswort, Titel, Text.
--}}
<x-hvm.alert variant="{{ $banner->variant }}" :title="$banner->title" class="mt-6" data-warnbanner="{{ $banner->kind }}">
    <p>{{ $banner->text }}</p>
</x-hvm.alert>
