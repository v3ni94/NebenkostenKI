{{--
    Pflicht-Warnhinweis der Kostenprüfung.

    Der Hinweis erklärt allgemein, warum die betroffenen Positionen besonders
    zu prüfen sind. Er ist ausdrücklich keine Rechtsberatung im Einzelfall.
--}}
<x-hvm.alert variant="{{ $banner->variant }}" class="mt-6" data-warnbanner="{{ $banner->kind }}">
    <p class="font-semibold">{{ $banner->title }}</p>
    <p class="mt-2">{{ $banner->text }}</p>
</x-hvm.alert>
