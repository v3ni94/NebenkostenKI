{{--
    Inline-SVG-Icons des HVM-Designsystems (Konzept A).

    Einheitlicher Satz mit 1,75 px Strichstaerke auf einem 24er Raster, Farbe
    ueber currentColor. Keine externen Ressourcen (CSP). Icons sind immer
    dekorativ (aria-hidden), die Bedeutung steht im Text daneben.

    Props:
      name   upload, document, house, key, shield, euro, check, check-circle,
             x-circle, info, warning, alert, arrow-right, plus, clock, list,
             user, lock, mail, trash, search, sparkle, layers, calendar, eye,
             inbox, grid, building, receipt, logout, menu, x, chevron-right,
             chevron-down

    Statuszuordnung (verbindlich, siehe docs/designsystem.md):
      Erledigt check-circle, Bitte pruefen eye, Fehlt noch inbox,
      Blockiert die Abrechnung alert.
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
        'alert' => 'M12 9v4m0 4h.01M10.3 4.3 2.7 17.5A2 2 0 0 0 4.4 20.5h15.2a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z',
        'eye' => 'M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12Zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'inbox' => 'M4 13h4l2 3h4l2-3h4M4 13l2.5-8h11L20 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-5Z',
        'grid' => 'M4 5a1 1 0 0 1 1-1h5v6H4V5Zm10-1h5a1 1 0 0 1 1 1v5h-6V4ZM4 14h6v6H5a1 1 0 0 1-1-1v-5Zm10 0h6v5a1 1 0 0 1-1 1h-5v-6Z',
        'building' => 'M4 21h16M6 21V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v16M9 8h2m2 0h2M9 12h2m2 0h2M9 16h2m2 0h2',
        'receipt' => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6m-6 4h4',
        'logout' => 'M14 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-2m-4-4h11m-3-3 3 3-3 3',
        'menu' => 'M4 7h16M4 12h16M4 17h16',
        'x' => 'M6 6l12 12M18 6 6 18',
        'chevron-right' => 'm9 6 6 6-6 6',
        'chevron-down' => 'm6 9 6 6 6-6',
    ];

    $pfad = $pfade[$name] ?? $pfade['info'];
@endphp

<svg {{ $attributes->class('h-5 w-5 shrink-0') }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="{{ $pfad }}" />
</svg>
