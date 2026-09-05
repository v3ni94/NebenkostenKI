{{--
    Inline-SVG-Icons des HVM-Designsystems (Konzept A).

    Einheitlicher Satz mit 1,75 px Strichstaerke auf einem 24er Raster, Farbe
    ueber currentColor. Keine externen Ressourcen (CSP). Icons sind immer
    dekorativ (aria-hidden), die Bedeutung steht im Text daneben.

    Props:
      name   upload, document, house, key, shield, euro, check, check-circle,
             x-circle, info, warning, arrow-right, plus, clock, list, user,
             lock, mail, trash, search, sparkle, layers, calendar
      class  Groesse ueber h-* w-*, Standard h-5 w-5
--}}
@props(['name'])

@php
    $pfade = [
        'upload' => 'M12 16V4m0 0 4 4m-4-4-4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2',
        'document' => 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Zm0 0v5h5M9 13h6M9 17h6',
        'house' => 'M3 11 12 4l9 7M5 10v9a1 1 0 0 0 1 1h4v-5h4v5h4a1 1 0 0 0 1-1v-9',
        'key' => 'M15 3a6 6 0 0 0-5.7 7.9L3 17.2V21h3.8l6.3-6.3A6 6 0 1 0 15 3Zm1.5 4.5h.01',
        'shield' => 'M12 3 4 6v6c0 4.4 3.4 8.1 8 9 4.6-.9 8-4.6 8-9V6l-8-3Zm-3 9 2 2 4-4',
        'euro' => 'M17 6.5A7 7 0 0 0 6.3 9M17 17.5A7 7 0 0 1 6.3 15M4 10.5h9M4 13.5h9',
        'check' => 'm5 12 4.5 4.5L19 7',
        'check-circle' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm-3.5-9 2.5 2.5 4.5-5',
        'x-circle' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM9.5 9.5l5 5m0-5-5 5',
        'info' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-9v5m0-8.5h.01',
        'warning' => 'M12 4 2.5 20h19L12 4Zm0 6v4.5m0 3h.01',
        'arrow-right' => 'M4 12h16m0 0-6-6m6 6-6 6',
        'plus' => 'M12 5v14M5 12h14',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2',
        'list' => 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
        'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9a8 8 0 0 1 16 0',
        'lock' => 'M7 11V8a5 5 0 0 1 10 0v3M6 11h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Zm6 4v2',
        'mail' => 'M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm-1 1 9 7 9-7',
        'trash' => 'M4 7h16M9 7V4h6v3m-7 0 .7 13h6.6L16 7',
        'search' => 'M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15Zm10.5 3-5-5',
        'sparkle' => 'M12 3v4m0 10v4M3 12h4m10 0h4M6.3 6.3l2.8 2.8m5.8 5.8 2.8 2.8M6.3 17.7l2.8-2.8m5.8-5.8 2.8-2.8',
        'layers' => 'm12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5',
        'calendar' => 'M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm-1 5h16M8 3v4m8-4v4',
    ];

    $pfad = $pfade[$name] ?? $pfade['info'];
@endphp

<svg {{ $attributes->class('h-5 w-5 shrink-0') }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="{{ $pfad }}" />
</svg>
