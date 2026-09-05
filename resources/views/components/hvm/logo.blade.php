{{--
    Logo der Hausverwaltung Mueller GmbH fuer die Webkoepfe.

    Die Datei stammt aus dem CI der Betreiberin und liegt unter public/ci
    (Logo_HVM.svg bevorzugt, sonst Logo_HVM.jpg, Seitenverhaeltnis 1320 x 1143).
    Fehlt beides, erscheint ein neutraler Textplatzhalter. Es wird kein Logo
    erzeugt, generiert oder nachgezeichnet.
--}}
@props(['height' => 'h-10'])
@php
    $logo = null;
    foreach (['ci/Logo_HVM.svg', 'ci/Logo_HVM.jpg'] as $kandidat) {
        if (is_file(public_path($kandidat))) {
            $logo = asset($kandidat);
            break;
        }
    }
@endphp
@if ($logo !== null)
    <img src="{{ $logo }}" alt="Hausverwaltung Müller GmbH" {{ $attributes->merge(['class' => $height.' w-auto']) }}>
@else
    <span {{ $attributes->merge(['class' => 'flex '.$height.' w-10 items-center justify-center rounded border border-hvm-hellgrau bg-hvm-umrissgrau text-[10px] leading-tight font-semibold text-hvm-anthrazit']) }}
          aria-hidden="true">HVM</span>
@endif
