{{--
    Anschriftfeld nach DIN 5008. Es werden ausschließlich übergebene Zeilen
    gedruckt, es wird nichts ergänzt.

    @var \App\Services\Pdf\View\PostalAddress $adresse
--}}
<div class="anschriftfeld">
    @foreach ($adresse->lines() as $zeile)
        {{ $zeile }}<br>
    @endforeach
</div>
